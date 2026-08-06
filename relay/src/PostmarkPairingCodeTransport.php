<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\PairingCodeTransport;
use AxelFerdinand\StatamicSecretaryRelay\Data\PairingCodeNotice;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayTransientFailure;
use JsonException;

final class PostmarkPairingCodeTransport implements PairingCodeTransport
{
    public function __construct(
        private readonly HttpTransport $http,
        private readonly string $serverToken,
        private readonly string $fromAddress,
        private readonly string $fromName = 'Secretary',
        private readonly string $messageStream = 'outbound',
        private readonly string $endpoint = 'https://api.postmarkapp.com/email',
    ) {
        if ($serverToken === ''
            || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\r\n]/', $fromName) === 1
            || $fromName === ''
            || $messageStream === ''
            || ! self::validEndpoint($endpoint)) {
            throw new RelayRejected('Postmark pairing transport configuration is invalid.');
        }
    }

    public function send(PairingCodeNotice $notice): string
    {
        if (filter_var($notice->recipient, FILTER_VALIDATE_EMAIL) === false
            || $notice->label === ''
            || mb_strlen($notice->label) > 120
            || preg_match('/[\r\n]/', $notice->label) === 1
            || preg_match('/^pc_[A-Za-z0-9_-]{43}$/D', $notice->code) !== 1
            || $notice->expiresAt <= time()) {
            throw new RelayRejected('Pairing-code notice is invalid.');
        }

        try {
            $body = json_encode([
                'From' => $this->fromName.' <'.$this->fromAddress.'>',
                'To' => $notice->recipient,
                'Subject' => 'Confirm Secretary',
                'TextBody' => $this->textBody($notice),
                'MessageStream' => $this->messageStream,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RelayRejected('Postmark pairing code could not be encoded.', previous: $exception);
        }

        $response = $this->http->post($this->endpoint, $body, [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Postmark-Server-Token' => $this->serverToken,
        ]);

        if (! $response->successful()) {
            if (in_array($response->status, [408, 425, 429], true) || $response->status >= 500) {
                throw new RelayTransientFailure('Postmark pairing delivery is temporarily unavailable.');
            }

            throw new RelayRejected('Postmark rejected the pairing code.');
        }

        try {
            $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RelayRejected('Postmark returned an invalid pairing response.', previous: $exception);
        }

        $providerMessageId = is_array($payload) ? (string) ($payload['MessageID'] ?? '') : '';

        if (($payload['ErrorCode'] ?? null) !== 0 || $providerMessageId === '' || mb_strlen($providerMessageId) > 255) {
            throw new RelayRejected('Postmark did not accept the pairing code.');
        }

        return $providerMessageId;
    }

    private function textBody(PairingCodeNotice $notice): string
    {
        $expires = gmdate('H:i', $notice->expiresAt).' UTC';

        return <<<TEXT
            Someone is connecting {$notice->label} to the shared Secretary address.

            One-time code:
            {$notice->code}

            The code expires at {$expires}. Paste it into the Secretary setup on the site. If you did not start this setup, you can safely ignore this email.
            TEXT;
    }

    private static function validEndpoint(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && mb_strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && mb_strtolower((string) ($parts['host'] ?? '')) === 'api.postmarkapp.com'
            && (($parts['port'] ?? 443) === 443)
            && (($parts['path'] ?? '') === '/email')
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }
}
