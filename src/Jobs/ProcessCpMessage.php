<?php

namespace AxelFerdinand\StatamicSecretary\Jobs;

use AxelFerdinand\StatamicSecretary\Agent\ConversationService;
use AxelFerdinand\StatamicSecretary\Models\Message;
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
        try {
            $this->process($conversations);
        } catch (Throwable $exception) {
            // The sync driver runs after-response jobs during kernel termination.
            // Contain the exception there so Laravel does not attempt to rewrite
            // headers after the redirect has already been sent. Persistent queue
            // drivers must still receive the exception so their retries work.
            if (config('queue.default') !== 'sync') {
                throw $exception;
            }

            $this->failed($exception);
        }
    }

    private function process(ConversationService $conversations): void
    {
        $message = Message::query()->with('conversation')->findOrFail($this->messageId);

        if (! $message->processed_at && $this->hasEarlierPendingMessage($message)) {
            if ($this->job) {
                $this->release(10);
            }

            return;
        }

        $user = User::find($message->conversation->user_id)
            ?? throw new RuntimeException('The Statamic user for this Secretary conversation no longer exists.');

        $conversations->respondTo($message, $user);
    }

    public function failed(?Throwable $exception): void
    {
        report($exception ?? new RuntimeException('Secretary CP processing failed.'));
        $message = Message::query()->find($this->messageId);

        if ($message && ! $message->processed_at) {
            $message->update([
                'processed_at' => now(),
                'metadata' => [
                    ...(array) $message->metadata,
                    'processing_error' => 'Secretary kunne ikke behandle meldingen. Kontroller loggen og prøv igjen.',
                ],
            ]);
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
