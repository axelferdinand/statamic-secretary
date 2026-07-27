<?php

namespace AxelFerdinand\StatamicSecretary\Data;

final readonly class AgentRequest
{
    /**
     * @param  array<int, array<string, mixed>>  $input
     * @param  array<int, array<string, mixed>>  $tools
     */
    public function __construct(
        public array $input,
        public array $tools = [],
        public ?string $previousResponseId = null,
        public ?string $safetyIdentifier = null,
        public ?string $instructions = null,
    ) {}
}
