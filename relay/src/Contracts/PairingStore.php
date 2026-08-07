<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

use AxelFerdinand\StatamicSecretaryRelay\Data\PairingDefinition;
use AxelFerdinand\StatamicSecretaryRelay\Data\PairingOutcome;

interface PairingStore
{
    public function issuePairing(
        string $codeDigest,
        PairingDefinition $definition,
        int $expiresAt,
    ): void;

    public function provisionPairing(
        string $codeDigest,
        string $claimFingerprint,
        string $webhookUrl,
        bool $requiresPayment = false,
    ): PairingOutcome;
}
