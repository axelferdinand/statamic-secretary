<?php

namespace AxelFerdinand\StatamicSecretary\Data;

final readonly class AgentResponse
{
    /**
     * @param  array<int, array<string, mixed>>  $output
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public string $id,
        public string $status,
        public array $output,
        public string $text,
        public array $usage = [],
    ) {}
}
