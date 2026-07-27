<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class HttpResult
{
    /** @param  array<string, string>  $headers */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = ['Content-Type' => 'application/json'],
    ) {}
}
