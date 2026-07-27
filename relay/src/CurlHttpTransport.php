<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransportResponse;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayTransientFailure;
use AxelFerdinand\StatamicSecretaryRelay\Security\PublicHttpsUrl;
use CurlHandle;

final class CurlHttpTransport implements HttpTransport
{
    public function __construct(
        private readonly PublicHttpsUrl $urlPolicy = new PublicHttpsUrl,
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $timeoutSeconds = 15,
        private readonly int $maximumResponseBytes = 1048576,
    ) {
        if (! extension_loaded('curl')
            || $connectTimeoutSeconds < 1
            || $connectTimeoutSeconds > 30
            || $timeoutSeconds < $connectTimeoutSeconds
            || $timeoutSeconds > 60
            || $maximumResponseBytes < 1024
            || $maximumResponseBytes > 5242880) {
            throw new RelayRejected('Relay HTTP transport configuration is invalid.');
        }
    }

    public function post(string $url, string $body, array $headers): HttpTransportResponse
    {
        if (strlen($body) > 1048576 || count($headers) > 50) {
            throw new RelayRejected('Relay HTTP request is too large.');
        }

        $resolved = $this->urlPolicy->resolve($url);
        $headerLines = $this->headerLines($headers);
        $responseBody = '';
        $responseTooLarge = false;
        $curl = curl_init();

        if (! $curl instanceof CurlHandle) {
            throw new RelayTransientFailure('Relay HTTP transport could not be initialized.');
        }

        $pinnedAddress = $resolved->addresses[0];
        $resolveAddress = str_contains($pinnedAddress, ':') ? '['.$pinnedAddress.']' : $pinnedAddress;
        curl_setopt_array($curl, [
            CURLOPT_URL => $resolved->url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$resolved->host.':'.$resolved->port.':'.$resolveAddress],
            CURLOPT_WRITEFUNCTION => function (
                CurlHandle $handle,
                string $chunk,
            ) use (&$responseBody, &$responseTooLarge): int {
                if (strlen($responseBody) + strlen($chunk) > $this->maximumResponseBytes) {
                    $responseTooLarge = true;

                    return 0;
                }

                $responseBody .= $chunk;

                return strlen($chunk);
            },
        ]);

        $result = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($curl);

        if ($result === false || $error !== CURLE_OK) {
            if ($responseTooLarge) {
                throw new RelayRejected('Relay HTTP response exceeded the size limit.');
            }

            throw new RelayTransientFailure('Relay HTTP request failed.');
        }

        if ($status < 100 || $status > 599) {
            throw new RelayTransientFailure('Relay HTTP response status is invalid.');
        }

        return new HttpTransportResponse($status, $responseBody);
    }

    /** @param  array<string, string>  $headers
     * @return array<int, string>
     */
    private function headerLines(array $headers): array
    {
        $blocked = ['host', 'content-length', 'transfer-encoding', 'connection'];
        $lines = [];

        foreach ($headers as $name => $value) {
            if (! is_string($name)
                || ! is_string($value)
                || preg_match('/^[A-Za-z0-9-]{1,64}$/D', $name) !== 1
                || in_array(mb_strtolower($name), $blocked, true)
                || preg_match('/[\r\n\0]/', $value) === 1
                || strlen($value) > 8192) {
                throw new RelayRejected('Relay HTTP headers are invalid.');
            }

            $lines[] = $name.': '.$value;
        }

        return $lines;
    }
}
