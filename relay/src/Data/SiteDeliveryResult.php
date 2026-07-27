<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class SiteDeliveryResult
{
    public function __construct(public string $conversationToken) {}
}
