<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\MailTransport;
use AxelFerdinand\StatamicSecretaryRelay\Data\OutboundReply;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayTransientFailure;
use JsonException;

final class PostmarkMailTransport implements MailTransport
{
    public function __construct(
        private readonly HttpTransport $http,
        private readonly string $serverToken,
        private readonly string $fromAddress,
        private readonly string $fromName = 'Statamic Secretary',
        private readonly string $messageStream = 'outbound',
        private readonly string $endpoint = 'https://api.postmarkapp.com/email',
    ) {
        if ($serverToken === ''
            || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\r\n]/', $fromName) === 1
            || $fromName === ''
            || $messageStream === ''
            || ! self::validEndpoint($endpoint)) {
            throw new RelayRejected('Postmark mail transport configuration is invalid.');
        }
    }

    public function send(OutboundReply $reply): string
    {
        $headers = [];

        if ($reply->inReplyTo !== null) {
            $headers = [
                ['Name' => 'In-Reply-To', 'Value' => $reply->inReplyTo],
                ['Name' => 'References', 'Value' => $reply->inReplyTo],
            ];
        }

        try {
            $body = json_encode([
                'From' => $this->fromName.' <'.$this->fromAddress.'>',
                'To' => $reply->recipient,
                'ReplyTo' => $reply->replyTo,
                'Subject' => $reply->subject,
                'TextBody' => $this->textBody($reply),
                'Headers' => $headers,
                'MessageStream' => $this->messageStream,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RelayRejected('Postmark reply could not be encoded.', previous: $exception);
        }

        $response = $this->http->post($this->endpoint, $body, [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Postmark-Server-Token' => $this->serverToken,
        ]);

        if (! $response->successful()) {
            if (in_array($response->status, [408, 425, 429], true) || $response->status >= 500) {
                throw new RelayTransientFailure('Postmark reply delivery is temporarily unavailable.');
            }

            throw new RelayRejected('Postmark rejected the relay reply.');
        }

        try {
            $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RelayRejected('Postmark returned an invalid reply response.', previous: $exception);
        }

        $providerMessageId = is_array($payload) ? (string) ($payload['MessageID'] ?? '') : '';

        if (($payload['ErrorCode'] ?? null) !== 0 || $providerMessageId === '' || mb_strlen($providerMessageId) > 255) {
            throw new RelayRejected('Postmark did not accept the relay reply.');
        }

        return $providerMessageId;
    }

    private function textBody(OutboundReply $reply): string
    {
        $body = trim($reply->body);

        if ($reply->changeSets !== []) {
            $body .= "\n\nEndringer i denne meldingen:";

            foreach ($reply->changeSets as $changeSet) {
                $status = $changeSet['status'] === 'published' ? 'publisert' : $changeSet['status'];
                $body .= "\n- {$changeSet['summary']} — {$status}";
            }
        }

        if ($reply->reviewUrl !== null) {
            $body .= "\n\nSe samtale og utkast:\n{$reply->reviewUrl}";
        }

        return $body."\n\nSvar på denne e-posten for å fortsette samme samtale.";
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
