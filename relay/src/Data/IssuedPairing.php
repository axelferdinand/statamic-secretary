<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class IssuedPairing
{
    public function __construct(
        public string $code,
        public int $expiresAt,
    ) {}
}
