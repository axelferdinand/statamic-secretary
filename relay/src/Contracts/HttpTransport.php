<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

interface HttpTransport
{
    /** @param  array<string, string>  $headers */
    public function post(string $url, string $body, array $headers): HttpTransportResponse;
}
