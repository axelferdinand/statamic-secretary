<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\PostmarkInboundSource;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\PostmarkPollStore;
use AxelFerdinand\StatamicSecretaryRelay\Data\ClaimState;
use AxelFerdinand\StatamicSecretaryRelay\Data\HttpResult;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkPoller;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HostedRelayPostmarkPollerTest extends TestCase
{
    public function test_pending_messages_are_processed_once_across_repeated_runs(): void
    {
        $source = new PollerInboundSource(['message-a', 'message-b']);
        $store = new PollerStore;
        $processed = [];
        $poller = new PostmarkPoller(
            $source,
            $store,
            static function (array $payload) use (&$processed): HttpResult {
                $processed[] = $payload['MessageID'];

                return new HttpResult(200, '{"accepted":true}');
            },
        );

        $this->assertSame(
            ['processed' => 2, 'deferred' => 0, 'skipped' => 0],
            $poller->run(),
        );
        $this->assertSame(
            ['processed' => 0, 'deferred' => 0, 'skipped' => 2],
            $poller->run(),
        );
        $this->assertSame(['message-a', 'message-b'], $processed);
    }

    public function test_retryable_application_results_release_the_poll_claim(): void
    {
        $source = new PollerInboundSource(['message-a']);
        $store = new PollerStore;
        $attempt = 0;
        $poller = new PostmarkPoller(
            $source,
            $store,
            static function () use (&$attempt): HttpResult {
                $attempt++;

                return new HttpResult($attempt === 1 ? 503 : 200, '{}');
            },
        );

        $this->assertSame(
            ['processed' => 0, 'deferred' => 1, 'skipped' => 0],
            $poller->run(),
        );
        $this->assertSame(
            ['processed' => 1, 'deferred' => 0, 'skipped' => 0],
            $poller->run(),
        );
    }

    public function test_source_or_processor_failures_release_the_poll_claim(): void
    {
        $source = new PollerInboundSource(['message-a']);
        $store = new PollerStore;
        $fail = true;
        $poller = new PostmarkPoller(
            $source,
            $store,
            static function () use (&$fail): HttpResult {
                if ($fail) {
                    $fail = false;

                    throw new RuntimeException('temporary');
                }

                return new HttpResult(200, '{}');
            },
        );

        try {
            $poller->run();
            $this->fail('A processor failure was hidden.');
        } catch (RuntimeException $exception) {
            $this->assertSame('temporary', $exception->getMessage());
        }

        $this->assertSame(
            ['processed' => 1, 'deferred' => 0, 'skipped' => 0],
            $poller->run(),
        );
    }
}

final class PollerInboundSource implements PostmarkInboundSource
{
    /** @param  array<int, string>  $messageIds */
    public function __construct(private readonly array $messageIds) {}

    public function pendingMessageIds(int $limit): array
    {
        return array_slice($this->messageIds, 0, $limit);
    }

    public function message(string $providerMessageId): array
    {
        return ['MessageID' => $providerMessageId];
    }
}

final class PollerStore implements PostmarkPollStore
{
    /** @var array<string, ClaimState> */
    private array $claims = [];

    public function claimPostmarkPoll(string $providerMessageId): ClaimState
    {
        if (($this->claims[$providerMessageId] ?? null) === ClaimState::Complete) {
            return ClaimState::Complete;
        }

        $this->claims[$providerMessageId] = ClaimState::Processing;

        return ClaimState::New;
    }

    public function completePostmarkPoll(string $providerMessageId): void
    {
        $this->claims[$providerMessageId] = ClaimState::Complete;
    }

    public function releasePostmarkPoll(string $providerMessageId): void
    {
        unset($this->claims[$providerMessageId]);
    }
}
