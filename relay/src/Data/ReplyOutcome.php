<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class ReplyOutcome
{
    public function __construct(
        public bool $duplicate,
        public ?string $providerMessageId = null,
    ) {}
}
