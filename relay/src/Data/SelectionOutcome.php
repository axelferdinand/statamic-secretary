<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class SelectionOutcome
{
    public function __construct(
        public string $status,
        public ?string $providerMessageId = null,
    ) {}
}
