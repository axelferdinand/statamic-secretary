<?php

namespace AxelFerdinand\StatamicSecretary\Jobs;

use AxelFerdinand\StatamicSecretary\Agent\ConversationService;
use AxelFerdinand\StatamicSecretary\Agent\PublicationIntentDetector;
use AxelFerdinand\StatamicSecretary\Email\EmailConfiguration;
use AxelFerdinand\StatamicSecretary\Email\ReplyLanguage;
use AxelFerdinand\StatamicSecretary\Mail\SecretaryReply;
use AxelFerdinand\StatamicSecretary\Models\Message;
use AxelFerdinand\StatamicSecretary\Relay\RelayClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Statamic\Facades\User;
use Throwable;

final class ProcessInboundEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 3;

    public int $timeout;

    public int $retryUntil;

    public function __construct(public readonly string $messageId)
    {
        $this->timeout = max(60, (int) config('secretary.limits.job_timeout', 1200));
        $this->retryUntil = now()->addSeconds(max(86_400, $this->timeout * 6))->getTimestamp();
        $this->onQueue('secretary');
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        $conversationId = Message::query()->whereKey($this->messageId)->value('conversation_id') ?: $this->messageId;

        return [(new WithoutOverlapping('secretary-conversation-'.$conversationId))
            ->shared()
            ->releaseAfter(10)
            ->expireAfter($this->timeout + 60)];
    }

    public function handle(ConversationService $conversations, PublicationIntentDetector $intent): void
    {
        try {
            $this->process($conversations, $intent);
        } catch (Throwable $exception) {
            // After-response jobs on Laravel's sync driver run after headers have
            // been sent. Record and notify about the failure without letting it
            // escape into kernel termination. Real queue drivers still retry.
            if (config('queue.default') !== 'sync') {
                throw $exception;
            }

            $this->failed($exception);
        }
    }

    private function process(ConversationService $conversations, PublicationIntentDetector $intent): void
    {
        $message = Message::query()->with('conversation')->findOrFail($this->messageId);

        if ($message->processed_at) {
            $reply = $this->existingReply($message);

            if ($reply) {
                $this->sendReply($message, $reply);

                return;
            }

            $message->update(['processed_at' => null]);
        }

        if ($this->hasEarlierPendingMessage($message)) {
            if ($this->job) {
                $this->release(10);
            }

            return;
        }

        $conversation = $message->conversation;
        $user = User::find($conversation->user_id)
            ?? throw new RuntimeException('The Statamic user for this Secretary email no longer exists.');

        $publicationRequested = $intent->matches($message->body);
        $senderAuthenticated = data_get($message->metadata, 'sender_authenticated') === true;
        $language = app(ReplyLanguage::class);
        $copy = $language->copy($language->forMessage($message));

        if ($publicationRequested && (! config('secretary.email.allow_publishing') || ! $senderAuthenticated)) {
            $reason = ! config('secretary.email.allow_publishing')
                ? $copy['publishing_disabled']
                : $copy['sender_not_authenticated'];
            $reply = $conversation->messages()->create([
                'direction' => 'outbound',
                'channel' => 'email',
                'role' => 'assistant',
                'body' => $reason.' '.$copy['open_cp_to_publish'],
                'reply_to_message_id' => $message->id,
                'metadata' => ['reply_to_message_id' => $message->id],
                'processed_at' => now(),
            ]);
            $message->update(['processed_at' => now()]);
        } else {
            $reply = $conversations->respondTo($message, $user);
        }

        $this->sendReply($message, $reply);
    }

    public function failed(?Throwable $exception): void
    {
        report($exception ?? new RuntimeException('Secretary email processing failed.'));
        $message = Message::query()->find($this->messageId);

        if (! $message) {
            return;
        }

        try {
            $language = app(ReplyLanguage::class);
            $copy = $language->copy($language->forMessage($message));
            $reply = $this->existingReply($message) ?? $message->conversation->messages()->create([
                'direction' => 'outbound',
                'channel' => 'email',
                'role' => 'assistant',
                'body' => $copy['processing_failed'],
                'reply_to_message_id' => $message->id,
                'metadata' => ['reply_to_message_id' => $message->id, 'processing_failed' => true],
                'processed_at' => now(),
            ]);
            $message->update([
                'processed_at' => now(),
                'metadata' => [
                    ...(array) $message->metadata,
                    'processing_error' => $copy['processing_error'],
                ],
            ]);
            $this->sendReply($message, $reply);
        } catch (Throwable $notificationException) {
            report($notificationException);
        }
    }

    private function existingReply(Message $message): ?Message
    {
        return $message->conversation->messages()
            ->where('direction', 'outbound')
            ->where('channel', 'email')
            ->where('reply_to_message_id', $message->id)
            ->first()
            ?? $message->conversation->messages()
                ->where('direction', 'outbound')
                ->where('channel', 'email')
                ->oldest('created_at')
                ->get()
                ->first(fn (Message $candidate): bool => data_get($candidate->metadata, 'reply_to_message_id') === $message->id);
    }

    private function sendReply(Message $message, Message $reply): void
    {
        if (filled(data_get($reply->metadata, 'email_sent_at'))) {
            return;
        }

        if (data_get($message->conversation->context, 'email_delivery') === 'relay') {
            app(RelayClient::class)->sendReply($message, $reply);
        } else {
            $email = app(EmailConfiguration::class);
            Mail::mailer($email->mailer())->to($message->conversation->email)->send(new SecretaryReply($message->conversation, $reply));
        }

        $reply->update(['metadata' => [
            ...(array) $reply->metadata,
            'email_sent_at' => now()->toIso8601String(),
        ]]);
    }

    private function hasEarlierPendingMessage(Message $message): bool
    {
        return $message->conversation->messages()
            ->where('direction', 'inbound')
            ->whereNull('processed_at')
            ->where('id', '<', $message->id)
            ->exists();
    }
}
