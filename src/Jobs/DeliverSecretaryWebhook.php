<?php

namespace AxelFerdinand\StatamicSecretary\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class DeliverSecretaryWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300, 900];

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly string $eventName,
        public readonly array $payload,
    ) {
        $this->onQueue('secretary');
    }

    public function handle(): void
    {
        $url = trim((string) config('secretary.developer.webhooks.url'));
        $secret = (string) config('secretary.developer.webhooks.secret');

        if ($url === '' || ! str_starts_with($url, 'https://') || mb_strlen($secret) < 32) {
            throw new RuntimeException('Secretary webhook delivery is enabled without a valid HTTPS URL and 32-character secret.');
        }

        $body = json_encode([
            'id' => (string) str()->ulid(),
            'event' => $this->eventName,
            'occurred_at' => now()->toIso8601String(),
            'data' => $this->payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $body, $secret);

        Http::asJson()
            ->acceptJson()
            ->timeout(max(1, min((int) config('secretary.developer.webhooks.timeout', 10), 30)))
            ->withHeaders([
                'X-Secretary-Event' => $this->eventName,
                'X-Secretary-Signature' => 'sha256='.$signature,
            ])
            ->withBody($body, 'application/json')
            ->post($url)
            ->throw();
    }
}
