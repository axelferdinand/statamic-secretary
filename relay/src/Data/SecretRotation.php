<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;

final readonly class SecretRotation
{
    public function __construct(
        public string $installationId,
        public string $rotationId,
        public string $signingSecret,
        public bool $duplicate = false,
    ) {
        if (preg_match('/^si_[a-z0-9_-]{20,125}$/D', $installationId) !== 1
            || preg_match('/^sr_[A-Za-z0-9_-]{43}$/D', $rotationId) !== 1
            || strlen($signingSecret) < 32) {
            throw new RelayRejected('Secret rotation configuration is invalid.');
        }
    }
}
