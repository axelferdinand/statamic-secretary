<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

use AxelFerdinand\StatamicSecretaryRelay\Data\InboundMessage;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Data\SiteDeliveryResult;

interface SiteTransport
{
    public function deliver(
        Installation $installation,
        InboundMessage $message,
        ?string $conversationToken,
        bool $acknowledgementSent = false,
    ): SiteDeliveryResult;
}
