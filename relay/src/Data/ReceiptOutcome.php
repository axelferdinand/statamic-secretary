<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class ReceiptOutcome
{
    public function __construct(
        public string $status,
        public int $milliseconds,
    ) {}
}
