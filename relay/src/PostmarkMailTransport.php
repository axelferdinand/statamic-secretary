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
                'HtmlBody' => $this->htmlBody($reply),
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
        $copy = (new ReplyLanguage)->copy($reply->locale);
        $body = trim($reply->body);
        $affectedChanges = array_values(array_filter(
            $reply->changeSets,
            fn (array $changeSet): bool => is_string($changeSet['public_url'] ?? null)
                && is_string($changeSet['resource_title'] ?? null),
        ));
        $affectedChange = count($affectedChanges) === 1 ? $affectedChanges[0] : null;

        $body = $this->withAffectedPage($body, $affectedChange, $copy);

        if (count($reply->changeSets) > 1
            || (count($reply->changeSets) === 1 && $affectedChange === null)) {
            $body .= "\n\n{$copy['prepared_changes']}:";

            foreach ($reply->changeSets as $changeSet) {
                $status = $changeSet['status'] === 'published' ? $copy['published'] : $copy['draft'];
                $body .= "\n- {$changeSet['summary']} — {$status}";
            }
        }

        $nativeChanges = array_values(array_filter(
            $reply->changeSets,
            fn (array $changeSet): bool => is_string($changeSet['native_url'] ?? null),
        ));

        if (count($nativeChanges) === 1) {
            $nativeChange = $nativeChanges[0];
            $label = $nativeChange['status'] === 'published'
                ? $copy['open_page']
                : $copy['open_draft'];
            $body .= "\n\n{$label}:\n{$nativeChange['native_url']}";
        }

        if ($reply->reviewUrl !== null) {
            $body .= "\n\n{$copy['continue_conversation']}:\n{$reply->reviewUrl}";
        }

        return $body."\n\n{$copy['reply_to_continue']}";
    }

    private function htmlBody(OutboundReply $reply): string
    {
        $lines = preg_split('/\R/u', $this->textBody($reply)) ?: [];
        $html = [];

        for ($index = 0, $count = count($lines); $index < $count; $index++) {
            $line = trim($lines[$index]);
            $next = $index + 1 < $count ? trim($lines[$index + 1]) : '';

            if (str_starts_with($line, '- ')
                && $this->isSafeLink($next)) {
                $html[] = '<a href="'.$this->escape($next).'" style="color:#2563eb;text-decoration:underline">'
                    .$this->escape(mb_substr($line, 2)).'</a>';
                $index++;

                continue;
            }

            if ($this->isSafeLink($line)) {
                $html[] = '<a href="'.$this->escape($line).'" style="color:#2563eb;text-decoration:underline">'
                    .$this->escape($line).'</a>';

                continue;
            }

            $html[] = $this->escape($line);
        }

        return '<!doctype html><html lang="'.$reply->locale.'"><head><meta charset="utf-8"></head>'
            .'<body style="margin:0;background:#f4f4f5;color:#18181b;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif">'
            .'<div style="max-width:640px;margin:0 auto;padding:32px 20px">'
            .'<div style="border:1px solid #e4e4e7;border-radius:12px;background:#ffffff;padding:28px;font-size:16px;line-height:1.65">'
            .implode('<br>', $html)
            .'</div></div></body></html>';
    }

    /**
     * @param  array<string, mixed>|null  $affectedChange
     * @param  array<string, string>  $copy
     */
    private function withAffectedPage(string $body, ?array $affectedChange, array $copy): string
    {
        if (! $affectedChange) {
            return $body;
        }

        $line = "{$copy['affected_page']}: {$affectedChange['resource_title']} — {$affectedChange['public_url']}";

        if (preg_match('/^Status:\s*.+$/miu', $body, $status, PREG_OFFSET_CAPTURE) !== 1) {
            return $body."\n\n".$line;
        }

        $offset = (int) $status[0][1];
        $before = rtrim(substr($body, 0, $offset));
        $after = ltrim(substr($body, $offset));

        return $before."\n\n".$line."\n\n".$after;
    }

    private function isSafeLink(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(mb_strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
