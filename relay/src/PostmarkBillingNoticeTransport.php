<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\BillingNoticeTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransport;
use AxelFerdinand\StatamicSecretaryRelay\Data\BillingNotice;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayTransientFailure;
use JsonException;

final class PostmarkBillingNoticeTransport implements BillingNoticeTransport
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
            throw new RelayRejected('Postmark billing notice configuration is invalid.');
        }
    }

    public function send(BillingNotice $notice): string
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
                'Subject' => 'Activate Relay for Secretary',
                'TextBody' => $this->textBody($notice),
                'HtmlBody' => $this->htmlBody($notice),
                'Headers' => $headers,
                'MessageStream' => $this->messageStream,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RelayRejected('Postmark billing notice could not be encoded.', previous: $exception);
        }

        $response = $this->http->post($this->endpoint, $body, [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Postmark-Server-Token' => $this->serverToken,
        ]);

        if (! $response->successful()) {
            if (in_array($response->status, [408, 425, 429], true) || $response->status >= 500) {
                throw new RelayTransientFailure('Postmark billing notice delivery is temporarily unavailable.');
            }

            throw new RelayRejected('Postmark rejected the billing notice.');
        }

        try {
            $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RelayRejected('Postmark returned an invalid billing notice response.', previous: $exception);
        }

        $providerMessageId = is_array($payload) ? (string) ($payload['MessageID'] ?? '') : '';

        if (($payload['ErrorCode'] ?? null) !== 0 || $providerMessageId === '' || mb_strlen($providerMessageId) > 255) {
            throw new RelayRejected('Postmark did not accept the billing notice.');
        }

        return $providerMessageId;
    }

    private function textBody(BillingNotice $notice): string
    {
        return "Secretary received your request, but email Relay is not active for {$notice->siteLabel} yet.\n\n"
            ."Activate Relay for USD 49/year:\n{$notice->checkoutUrl}\n\n"
            .'Nothing was changed or published. After payment, finish the connection in Secretary '
            ."and send your request again.\n\n"
            .'This is an automated service message. Please do not reply.';
    }

    private function htmlBody(BillingNotice $notice): string
    {
        $label = $this->escape($notice->siteLabel);
        $checkoutUrl = $this->escape($notice->checkoutUrl);

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"></head>'
            .'<body style="margin:0;background:#f4f4f5;color:#18181b;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif">'
            .'<div style="max-width:640px;margin:0 auto;padding:32px 20px">'
            .'<div style="border:1px solid #e4e4e7;border-radius:12px;background:#ffffff;padding:28px;font-size:16px;line-height:1.65">'
            .'<h1 style="font-size:24px;line-height:1.25;margin:0 0 16px">Activate email Relay</h1>'
            .'<p style="margin:0 0 20px">Secretary received your request, but email Relay is not active for <strong>'.$label.'</strong> yet.</p>'
            .'<p style="margin:0 0 24px"><a href="'.$checkoutUrl.'" style="display:inline-block;border-radius:8px;background:#4f2ee8;color:#ffffff;padding:12px 18px;text-decoration:none;font-weight:700">Activate Relay · USD 49/year</a></p>'
            .'<p style="margin:0 0 16px">Nothing was changed or published. After payment, finish the connection in Secretary and send your request again.</p>'
            .'<p style="margin:0;color:#71717a;font-size:14px">This is an automated service message. Please do not reply.</p>'
            .'</div></div></body></html>';
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
