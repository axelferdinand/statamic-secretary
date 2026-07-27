<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class ConversationRoute
{
    public function __construct(
        public string $token,
        public string $installationId,
        public string $routeToken,
        public string $sender,
    ) {}
}
