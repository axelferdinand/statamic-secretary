<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

use AxelFerdinand\StatamicSecretaryRelay\Data\RateLimitDecision;

interface RateLimitStore
{
    public function consumeRateLimit(
        string $scope,
        string $subject,
        int $limit,
        int $windowSeconds,
    ): RateLimitDecision;
}
