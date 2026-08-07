<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class BillingNotice
{
    public function __construct(
        public string $providerMessageId,
        public string $installationId,
        public string $recipient,
        public string $siteLabel,
        public string $checkoutUrl,
        public ?string $inReplyTo = null,
    ) {}
}
