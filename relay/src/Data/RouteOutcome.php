<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class RouteOutcome
{
    /** @param  array<int, string>  $candidateRouteTokens */
    public function __construct(
        public string $status,
        public ?string $installationId = null,
        public ?string $conversationToken = null,
        public array $candidateRouteTokens = [],
        public ?int $acknowledgementMilliseconds = null,
    ) {}
}
