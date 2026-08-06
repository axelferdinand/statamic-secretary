<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SelectionTransport;
use AxelFerdinand\StatamicSecretaryRelay\Data\SelectionNotice;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayTransientFailure;
use JsonException;

final class PostmarkSelectionTransport implements SelectionTransport
{
    public function __construct(
        private readonly HttpTransport $http,
        private readonly string $serverToken,
        private readonly string $fromAddress,
        private readonly string $fromName = 'Secretary',
        private readonly string $messageStream = 'outbound',
    ) {
        if ($serverToken === ''
            || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\r\n]/', $fromName) === 1
            || $fromName === ''
            || $messageStream === '') {
            throw new RelayRejected('Postmark selection transport configuration is invalid.');
        }
    }

    public function send(SelectionNotice $notice): string
    {
        $headers = $notice->inReplyTo !== null
            ? [
                ['Name' => 'In-Reply-To', 'Value' => $notice->inReplyTo],
                ['Name' => 'References', 'Value' => $notice->inReplyTo],
            ]
            : [];

        try {
            $body = json_encode([
                'From' => $this->fromName.' <'.$this->fromAddress.'>',
                'To' => $notice->recipient,
                'Subject' => 'Choose a site for Secretary',
                'TextBody' => $this->textBody($notice),
                'Headers' => $headers,
                'MessageStream' => $this->messageStream,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RelayRejected('Postmark selection notice could not be encoded.', previous: $exception);
        }

        $response = $this->http->post('https://api.postmarkapp.com/email', $body, [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Postmark-Server-Token' => $this->serverToken,
        ]);

        if (! $response->successful()) {
            if (in_array($response->status, [408, 425, 429], true) || $response->status >= 500) {
                throw new RelayTransientFailure('Postmark selection delivery is temporarily unavailable.');
            }

            throw new RelayRejected('Postmark rejected the selection notice.');
        }

        try {
            $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RelayRejected('Postmark returned an invalid selection response.', previous: $exception);
        }

        $providerMessageId = is_array($payload) ? (string) ($payload['MessageID'] ?? '') : '';

        if (($payload['ErrorCode'] ?? null) !== 0 || $providerMessageId === '' || mb_strlen($providerMessageId) > 255) {
            throw new RelayRejected('Postmark did not accept the selection notice.');
        }

        return $providerMessageId;
    }

    private function textBody(SelectionNotice $notice): string
    {
        $body = "Your request was not sent to a site because your email address is connected to multiple Statamic sites.\n\n";
        $body .= "Send the original request again to one of these addresses:\n";

        foreach ($notice->candidates as $candidate) {
            $body .= "\n- {$candidate['label']}: {$candidate['address']}";
        }

        return $body."\n\nDo not reply directly to this message; use one of the addresses above.";
    }
}
