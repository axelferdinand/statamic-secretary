<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Exceptions;

final class RelayRateLimited extends RelayRejected
{
    public function __construct(
        public readonly string $scope,
        public readonly int $retryAfter,
    ) {
        parent::__construct('Relay request rate limit exceeded.');
    }
}
