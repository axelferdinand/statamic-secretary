<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Data\InboundMessage;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;

final class PostmarkInboundAdapter
{
    public function __construct(
        private readonly string $sharedAddress,
        private readonly bool $requireSenderAuthentication = true,
        private readonly float $maximumSpamScore = 5.0,
        private readonly int $maximumCharacters = 20000,
    ) {
        new RelayAddress($sharedAddress);
    }

    /** @param  array<string, mixed>  $payload */
    public function adapt(array $payload): InboundMessage
    {
        $providerMessageId = $payload['MessageID'] ?? null;
        $mailboxHash = $payload['MailboxHash'] ?? '';
        $sender = $payload['FromFull']['Email'] ?? null;
        $subject = $payload['Subject'] ?? null;
        $headers = $payload['Headers'] ?? [];
        $attachments = $payload['Attachments'] ?? [];

        if (! is_string($providerMessageId)
            || $providerMessageId === ''
            || mb_strlen($providerMessageId) > 255
            || ! is_string($mailboxHash)
            || mb_strlen($mailboxHash) > 53
            || ! is_string($sender)
            || filter_var(mb_strtolower(trim($sender)), FILTER_VALIDATE_EMAIL) === false
            || ($subject !== null && (! is_string($subject) || mb_strlen($subject) > 998))
            || ! is_array($headers)
            || count($headers) > 200
            || ! is_array($attachments)
            || $attachments !== []) {
            throw new RelayRejected('Postmark inbound payload failed validation.');
        }

        $body = trim(is_string($payload['StrippedTextReply'] ?? null) ? $payload['StrippedTextReply'] : '');

        if ($body === '') {
            $body = trim(is_string($payload['TextBody'] ?? null) ? $payload['TextBody'] : '');
        }

        if ($body === '' || mb_strlen($body) > max(1, $this->maximumCharacters)) {
            throw new RelayRejected('Postmark inbound message has no acceptable plain-text body.');
        }

        [$local, $domain] = explode('@', mb_strtolower($this->sharedAddress), 2);
        $recipient = $local.(trim($mailboxHash) !== '' ? '+'.mb_strtolower(trim($mailboxHash)) : '').'@'.$domain;
        (new RelayAddress($this->sharedAddress))->parse($recipient);
        $authentication = $this->authentication($headers);

        return new InboundMessage(
            $providerMessageId,
            $recipient,
            mb_strtolower(trim($sender)),
            $body,
            $subject,
            $authentication['authenticated'],
            $authentication['spam_score'],
            $this->rfcMessageId($headers),
        );
    }

    /**
     * @param  array<int, mixed>  $headers
     * @return array{authenticated: bool, spam_score: float|null}
     */
    private function authentication(array $headers): array
    {
        $grouped = [];

        foreach ($headers as $header) {
            if (! is_array($header)
                || ! is_string($header['Name'] ?? null)
                || mb_strlen($header['Name']) > 255
                || (! is_null($header['Value'] ?? null) && ! is_string($header['Value']))) {
                throw new RelayRejected('Postmark inbound headers failed validation.');
            }

            $name = mb_strtolower($header['Name']);
            $grouped[$name][] = (string) ($header['Value'] ?? '');
        }

        if (count($grouped['x-spam-tests'] ?? []) > 1 || count($grouped['x-spam-score'] ?? []) > 1) {
            throw new RelayRejected('Postmark authentication headers are ambiguous.');
        }

        $scoreValue = $grouped['x-spam-score'][0] ?? '';
        $score = is_numeric($scoreValue) ? (float) $scoreValue : null;

        if ($score !== null && $score > $this->maximumSpamScore) {
            throw new RelayRejected('Postmark spam score exceeded the relay threshold.');
        }

        $tests = preg_split('/\s*,\s*/', mb_strtoupper($grouped['x-spam-tests'][0] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $authenticated = in_array('DKIM_VALID_AU', $tests ?: [], true);

        if ($this->requireSenderAuthentication && ! $authenticated) {
            throw new RelayRejected('Postmark sender did not pass author-domain DKIM.');
        }

        return ['authenticated' => $authenticated, 'spam_score' => $score];
    }

    /** @param  array<int, mixed>  $headers */
    private function rfcMessageId(array $headers): ?string
    {
        $values = [];

        foreach ($headers as $header) {
            if (is_array($header) && mb_strtolower((string) ($header['Name'] ?? '')) === 'message-id') {
                $values[] = $header['Value'] ?? null;
            }
        }

        if (count($values) !== 1 || ! is_string($values[0])) {
            return null;
        }

        $value = trim($values[0]);

        return preg_match('/^<[^<>\s@]+@[^<>\s@]+>$/D', $value) === 1 ? $value : null;
    }
}
