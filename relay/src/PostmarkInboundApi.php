<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\PostmarkInboundSource;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayTransientFailure;
use CurlHandle;
use JsonException;

final class PostmarkInboundApi implements PostmarkInboundSource
{
    private const BASE_URL = 'https://api.postmarkapp.com';

    public function __construct(
        private readonly string $serverToken,
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $timeoutSeconds = 20,
        private readonly int $maximumResponseBytes = 5242880,
    ) {
        if (! extension_loaded('curl')
            || trim($serverToken) === ''
            || strlen($serverToken) > 255
            || $connectTimeoutSeconds < 1
            || $connectTimeoutSeconds > 30
            || $timeoutSeconds < $connectTimeoutSeconds
            || $timeoutSeconds > 60
            || $maximumResponseBytes < 1048576
            || $maximumResponseBytes > 10485760) {
            throw new RelayRejected('Postmark inbound API configuration is invalid.');
        }
    }

    public function pendingMessageIds(int $limit): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new RelayRejected('Postmark poll limit is invalid.');
        }

        $messageIds = [];

        foreach (['scheduled', 'failed'] as $status) {
            $payload = $this->get('/messages/inbound?'.http_build_query([
                'count' => min(500, max(100, $limit)),
                'offset' => 0,
                'status' => $status,
            ]));

            if (! is_array($payload['InboundMessages'] ?? null)) {
                throw new RelayTransientFailure('Postmark inbound search returned an invalid response.');
            }

            foreach ($payload['InboundMessages'] as $message) {
                $messageId = is_array($message) ? ($message['MessageID'] ?? null) : null;

                if (! is_string($messageId) || ! $this->validMessageId($messageId)) {
                    throw new RelayTransientFailure('Postmark inbound search returned an invalid message identity.');
                }

                $messageIds[$messageId] = true;

                if (count($messageIds) >= $limit) {
                    break 2;
                }
            }
        }

        return array_keys($messageIds);
    }

    public function message(string $providerMessageId): array
    {
        if (! $this->validMessageId($providerMessageId)) {
            throw new RelayRejected('Postmark message identity is invalid.');
        }

        $payload = $this->get('/messages/inbound/'.rawurlencode($providerMessageId).'/details');

        if (! is_string($payload['MessageID'] ?? null)
            || ! hash_equals($providerMessageId, $payload['MessageID'])) {
            throw new RelayTransientFailure('Postmark inbound details returned a different message identity.');
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        if (! str_starts_with($path, '/messages/inbound')) {
            throw new RelayRejected('Postmark inbound API path is invalid.');
        }

        $responseBody = '';
        $responseTooLarge = false;
        $curl = curl_init(self::BASE_URL.$path);

        if (! $curl instanceof CurlHandle) {
            throw new RelayTransientFailure('Postmark inbound API request could not be initialized.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-Postmark-Server-Token: '.$this->serverToken,
            ],
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
            throw $responseTooLarge
                ? new RelayRejected('Postmark inbound API response exceeded the size limit.')
                : new RelayTransientFailure('Postmark inbound API request failed.');
        }

        if ($status !== 200) {
            throw new RelayTransientFailure('Postmark inbound API returned a non-success response.');
        }

        try {
            $decoded = json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RelayTransientFailure(
                'Postmark inbound API returned invalid JSON.',
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new RelayTransientFailure('Postmark inbound API response is invalid.');
        }

        return $decoded;
    }

    private function validMessageId(string $messageId): bool
    {
        return preg_match('/^[A-Za-z0-9-]{1,255}$/D', $messageId) === 1;
    }
}
