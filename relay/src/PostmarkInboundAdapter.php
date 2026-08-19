<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Data\InboundAttachment;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundMessage;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;

final class PostmarkInboundAdapter
{
    public function __construct(
        private readonly string $sharedAddress,
        private readonly bool $requireSenderAuthentication = true,
        private readonly float $maximumSpamScore = 5.0,
        private readonly int $maximumCharacters = 20000,
        private readonly int $maximumAttachments = 4,
        private readonly int $maximumAttachmentBytes = 8_000_000,
        private readonly int $maximumTotalAttachmentBytes = 16_000_000,
    ) {
        new RelayAddress($sharedAddress);

        if ($maximumAttachments < 1
            || $maximumAttachments > 10
            || $maximumAttachmentBytes < 1
            || $maximumTotalAttachmentBytes < $maximumAttachmentBytes) {
            throw new RelayRejected('Postmark attachment limits are invalid.');
        }
    }

    /** @param  array<string, mixed>  $payload */
    public function adapt(array $payload): InboundMessage
    {
        $providerMessageId = $payload['MessageID'] ?? null;
        $mailboxHash = $payload['MailboxHash'] ?? '';
        $toFull = $payload['ToFull'] ?? [];
        $sender = $payload['FromFull']['Email'] ?? null;
        $subject = $payload['Subject'] ?? null;
        $headers = $payload['Headers'] ?? [];
        $attachments = $payload['Attachments'] ?? [];

        if (! is_string($providerMessageId)
            || $providerMessageId === ''
            || mb_strlen($providerMessageId) > 255
            || ! is_string($mailboxHash)
            || mb_strlen($mailboxHash) > 53
            || ! is_array($toFull)
            || count($toFull) > 200
            || ! is_string($sender)
            || filter_var(mb_strtolower(trim($sender)), FILTER_VALIDATE_EMAIL) === false
            || ($subject !== null && (! is_string($subject) || mb_strlen($subject) > 998))
            || ! is_array($headers)
            || count($headers) > 200
            || ! is_array($attachments)
            || count($attachments) > $this->maximumAttachments) {
            throw new RelayRejected('Postmark inbound payload failed validation.');
        }

        $attachments = array_map(
            fn (mixed $attachment): InboundAttachment => is_array($attachment)
                ? InboundAttachment::fromPostmark($attachment, $this->maximumAttachmentBytes)
                : throw new RelayRejected('Postmark image attachment failed validation.'),
            $attachments,
        );

        if (array_sum(array_map(
            fn (InboundAttachment $attachment): int => $attachment->size,
            $attachments,
        )) > $this->maximumTotalAttachmentBytes) {
            throw new RelayRejected('Postmark image attachments exceed the total size limit.');
        }

        $body = trim(is_string($payload['StrippedTextReply'] ?? null) ? $payload['StrippedTextReply'] : '');

        if ($body === '') {
            $body = trim(is_string($payload['TextBody'] ?? null) ? $payload['TextBody'] : '');
        }

        if ($body === '' && $attachments !== []) {
            $body = 'Attached image: '.implode(', ', array_map(
                fn (InboundAttachment $attachment): string => $attachment->name,
                $attachments,
            ));
        }

        if ($body === '' || mb_strlen($body) > max(1, $this->maximumCharacters)) {
            throw new RelayRejected('Postmark inbound message has no acceptable plain-text body.');
        }

        $recipient = $this->recipient($mailboxHash, $toFull);
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
            $attachments,
        );
    }

    /**
     * Direct inbound-domain delivery preserves a readable alias in ToFull.
     * Legacy cPanel forwards may leave the top-level MailboxHash empty while
     * retaining the original tagged recipient and hash in ToFull.
     *
     * @param  array<int, mixed>  $recipients
     */
    private function recipient(string $primary, array $recipients): string
    {
        $primary = mb_strtolower(trim($primary));
        [$sharedLocal, $sharedDomain] = explode('@', mb_strtolower($this->sharedAddress), 2);
        $prefix = $sharedLocal.'+';
        $hashCandidates = [];
        $aliasCandidates = [];

        foreach ($recipients as $recipient) {
            if (! is_array($recipient)
                || ! is_string($recipient['Email'] ?? null)
                || mb_strlen($recipient['Email']) > 255
                || ! is_string($recipient['MailboxHash'] ?? null)
                || mb_strlen($recipient['MailboxHash']) > 53) {
                throw new RelayRejected('Postmark inbound recipients failed validation.');
            }

            $email = mb_strtolower(trim($recipient['Email']));
            $mailboxHash = mb_strtolower(trim($recipient['MailboxHash']));

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new RelayRejected('Postmark inbound recipients failed validation.');
            }

            [$local, $domain] = explode('@', $email, 2);

            if (! hash_equals($sharedDomain, $domain)) {
                continue;
            }

            if (str_starts_with($local, $prefix)) {
                $tag = substr($local, strlen($prefix));

                if ($mailboxHash === '' || ! hash_equals($tag, $mailboxHash)) {
                    throw new RelayRejected('Postmark inbound recipient hash is inconsistent.');
                }

                (new RelayAddress($this->sharedAddress))->parse($email);
                $hashCandidates[$mailboxHash] = true;

                continue;
            }

            if (hash_equals($sharedLocal, $local)) {
                if ($mailboxHash !== '') {
                    throw new RelayRejected('Postmark inbound recipient hash is inconsistent.');
                }

                continue;
            }

            if (PublicSiteAlias::valid($local)) {
                if ($mailboxHash !== '') {
                    throw new RelayRejected('Postmark inbound public alias is inconsistent.');
                }

                $aliasCandidates[$email] = true;
            }
        }

        $hashes = array_keys($hashCandidates);
        $aliases = array_keys($aliasCandidates);

        if (count($hashes) > 1
            || count($aliases) > 1
            || ($primary !== '' && $hashes !== [] && ! isset($hashCandidates[$primary]))
            || ($hashes !== [] && $aliases !== [])) {
            throw new RelayRejected('Postmark inbound recipient hashes are ambiguous.');
        }

        if ($primary !== '' || $hashes !== []) {
            $hash = $primary !== '' ? $primary : $hashes[0];

            return $sharedLocal.'+'.$hash.'@'.$sharedDomain;
        }

        return $aliases[0] ?? mb_strtolower($this->sharedAddress);
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
