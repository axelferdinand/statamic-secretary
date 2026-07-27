<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

final readonly class HttpTransportResponse
{
    public function __construct(public int $status, public string $body) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
