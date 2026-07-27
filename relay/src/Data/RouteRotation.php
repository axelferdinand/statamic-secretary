<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;

final readonly class RouteRotation
{
    public function __construct(
        public string $installationId,
        public string $rotationId,
        public string $routeToken,
        public bool $duplicate = false,
    ) {
        if (preg_match('/^si_[a-z0-9_-]{20,125}$/D', $installationId) !== 1
            || preg_match('/^rr_[A-Za-z0-9_-]{43}$/D', $rotationId) !== 1
            || preg_match('/^r[a-z0-9]{25}$/D', $routeToken) !== 1) {
            throw new RelayRejected('Route rotation configuration is invalid.');
        }
    }
}
