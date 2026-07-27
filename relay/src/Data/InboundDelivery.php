<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class InboundDelivery
{
    public function __construct(
        public string $providerMessageId,
        public string $installationId,
        public string $sender,
        public string $routeToken,
        public string $conversationToken,
    ) {}
}
