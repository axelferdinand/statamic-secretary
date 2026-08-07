<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class BillingNoticeOutcome
{
    public function __construct(
        public string $status,
        public ?string $providerMessageId = null,
    ) {}
}
