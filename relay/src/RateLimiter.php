<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\RateLimitStore;
use AxelFerdinand\StatamicSecretaryRelay\Data\RateLimitDecision;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;

final readonly class RateLimiter
{
    /**
     * @param  array<string, int>  $limits
     */
    public function __construct(
        private RateLimitStore $store,
        private array $limits,
        private int $windowSeconds = 60,
    ) {
        if ($limits === []
            || $windowSeconds < 10
            || $windowSeconds > 3600
            || ! $this->validLimits($limits)) {
            throw new RelayRejected('Relay rate-limit configuration is invalid.');
        }
    }

    public function attempt(string $scope, string $subject): RateLimitDecision
    {
        if (! array_key_exists($scope, $this->limits)) {
            throw new RelayRejected('Relay rate-limit scope is invalid.');
        }

        return $this->store->consumeRateLimit(
            $scope,
            $subject,
            $this->limits[$scope],
            $this->windowSeconds,
        );
    }

    /** @param  array<string, int>  $limits */
    private function validLimits(array $limits): bool
    {
        foreach ($limits as $scope => $limit) {
            if (! is_string($scope)
                || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $scope) !== 1
                || ! is_int($limit)
                || $limit < 1
                || $limit > 100000) {
                return false;
            }
        }

        return true;
    }
}
