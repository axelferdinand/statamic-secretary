<?php

namespace AxelFerdinand\StatamicSecretary\Jobs;

use AxelFerdinand\StatamicSecretary\Agent\ConversationService;
use AxelFerdinand\StatamicSecretary\Models\Message;
use AxelFerdinand\StatamicSecretary\Support\PublicError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Statamic\Facades\User;
use Throwable;

final class ProcessCpMessage implements ShouldQueue
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

    public function handle(ConversationService $conversations): void
    {
        $processed = false;

        try {
            $processed = $this->process($conversations);
        } catch (Throwable $exception) {
            // The sync driver runs after-response jobs during kernel termination.
            // Contain the exception there so Laravel does not attempt to rewrite
            // headers after the redirect has already been sent. Persistent queue
            // drivers must still receive the exception so their retries work.
            if (config('queue.default') !== 'sync') {
                throw $exception;
            }

            $this->failed($exception);
            $processed = true;
        }

        if (config('queue.default') === 'sync' && $processed) {
            $this->drainPendingConversation($conversations);
        }
    }

    private function process(ConversationService $conversations): bool
    {
        $message = Message::query()->with('conversation')->findOrFail($this->messageId);

        if (! $message->processed_at && $this->hasEarlierPendingMessage($message)) {
            if ($this->job) {
                $this->release(10);
            }

            return false;
        }

        $user = User::find($message->conversation->user_id)
            ?? throw new RuntimeException('The Statamic user for this Secretary conversation no longer exists.');

        $conversations->respondTo($message, $user);

        return true;
    }

    public function failed(?Throwable $exception): void
    {
        report($exception ?? new RuntimeException('Secretary CP processing failed.'));
        $this->markFailed($this->messageId, $exception);
    }

    private function markFailed(string $messageId, ?Throwable $exception = null): void
    {
        $message = Message::query()->find($messageId);

        if ($message && ! $message->processed_at) {
            $message->update([
                'processed_at' => now(),
                'metadata' => [
                    ...(array) $message->metadata,
                    'processing_error' => PublicError::message(
                        $exception ?? new RuntimeException('Secretary CP processing failed.'),
                        'Secretary hit a temporary problem. Your request is safe—edit it if needed, then try again. If it keeps happening, ask an administrator to run Secretary’s system checks.',
                    ),
                ],
            ]);
        }
    }

    private function drainPendingConversation(ConversationService $conversations): void
    {
        $conversationId = Message::query()->whereKey($this->messageId)->value('conversation_id');

        if (! $conversationId) {
            return;
        }

        for ($processed = 0; $processed < 50; $processed++) {
            $next = Message::query()
                ->where('conversation_id', $conversationId)
                ->where('direction', 'inbound')
                ->whereNull('processed_at')
                ->oldest('created_at')
                ->oldest('id')
                ->first();

            if (! $next) {
                return;
            }

            try {
                $user = User::find($next->conversation->user_id)
                    ?? throw new RuntimeException('The Statamic user for this Secretary conversation no longer exists.');

                $conversations->respondTo($next, $user);
            } catch (Throwable $exception) {
                report($exception);
                $this->markFailed($next->id, $exception);
            }
        }
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
