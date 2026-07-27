<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class ParsedAddress
{
    public function __construct(
        public ?string $routeToken,
        public ?string $conversationToken,
    ) {}
}
