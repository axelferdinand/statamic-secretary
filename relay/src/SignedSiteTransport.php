<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SiteTransport;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundMessage;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Data\SiteDeliveryResult;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayTransientFailure;
use AxelFerdinand\StatamicSecretaryRelay\Security\Signature;
use JsonException;

final class SignedSiteTransport implements SiteTransport
{
    public function __construct(private readonly HttpTransport $http) {}

    public function deliver(Installation $installation, InboundMessage $message, ?string $conversationToken): SiteDeliveryResult
    {
        try {
            $body = json_encode(
                $message->sitePayload($installation->routeToken, $conversationToken),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new RelayRejected('Site delivery payload could not be encoded.', previous: $exception);
        }

        $path = (string) parse_url($installation->webhookUrl, PHP_URL_PATH);
        $headers = [
            ...Signature::headers($installation, 'POST', $path, $body),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        $response = $this->http->post($installation->webhookUrl, $body, $headers);

        if (! $response->successful()) {
            if (in_array($response->status, [408, 425, 429], true) || $response->status >= 500) {
                throw new RelayTransientFailure('Site delivery is temporarily unavailable.');
            }

            throw new RelayRejected('Site rejected the relay delivery.');
        }

        try {
            $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RelayRejected('Site returned an invalid relay response.', previous: $exception);
        }

        $returnedToken = is_array($payload) ? (string) ($payload['conversation_token'] ?? '') : '';

        if (($payload['accepted'] ?? null) !== true
            || preg_match('/^c[a-z0-9]{25}$/D', $returnedToken) !== 1
            || ($conversationToken !== null && ! hash_equals($conversationToken, $returnedToken))) {
            throw new RelayRejected('Site returned an invalid conversation binding.');
        }

        return new SiteDeliveryResult($returnedToken);
    }
}
