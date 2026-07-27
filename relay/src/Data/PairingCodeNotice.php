<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class PairingCodeNotice
{
    public function __construct(
        public string $recipient,
        public string $label,
        public string $code,
        public int $expiresAt,
    ) {}
}
