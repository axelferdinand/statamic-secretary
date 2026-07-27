<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class ResolvedHttpsUrl
{
    /** @param  array<int, string>  $addresses */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public array $addresses,
    ) {}
}
