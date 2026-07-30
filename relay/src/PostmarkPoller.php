<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\PostmarkInboundSource;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\PostmarkPollStore;
use AxelFerdinand\StatamicSecretaryRelay\Data\ClaimState;
use AxelFerdinand\StatamicSecretaryRelay\Data\HttpResult;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayTransientFailure;
use Closure;
use Throwable;

final class PostmarkPoller
{
    private readonly Closure $processor;

    public function __construct(
        private readonly PostmarkInboundSource $source,
        private readonly PostmarkPollStore $store,
        callable $processor,
    ) {
        $this->processor = Closure::fromCallable($processor);
    }

    /** @return array{processed: int, deferred: int, skipped: int} */
    public function run(int $limit = 50): array
    {
        $processed = 0;
        $deferred = 0;
        $skipped = 0;

        foreach ($this->source->pendingMessageIds($limit) as $providerMessageId) {
            $claim = $this->store->claimPostmarkPoll($providerMessageId);

            if ($claim !== ClaimState::New) {
                $skipped++;

                continue;
            }

            try {
                $payload = $this->source->message($providerMessageId);
                $result = ($this->processor)($payload);

                if (! $result instanceof HttpResult) {
                    throw new RelayTransientFailure('Postmark poll processor returned an invalid result.');
                }

                if ($result->status !== 200) {
                    $this->store->releasePostmarkPoll($providerMessageId);
                    $deferred++;

                    continue;
                }

                $this->store->completePostmarkPoll($providerMessageId);
                $processed++;
            } catch (Throwable $exception) {
                $this->store->releasePostmarkPoll($providerMessageId);

                throw $exception;
            }
        }

        return compact('processed', 'deferred', 'skipped');
    }
}
