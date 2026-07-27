<?php

use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Security\PublicHttpsUrl;

require dirname(__DIR__).'/bootstrap.php';

$options = getopt('', ['public-url::']);
$publicUrl = is_string($options['public-url'] ?? null)
    ? trim($options['public-url'])
    : trim((string) getenv('RELAY_PUBLIC_URL'));
$serverToken = trim((string) getenv('RELAY_POSTMARK_SERVER_TOKEN'));
$username = trim((string) getenv('RELAY_POSTMARK_WEBHOOK_USER'));
$password = trim((string) getenv('RELAY_POSTMARK_WEBHOOK_PASSWORD'));

try {
    if ($publicUrl === ''
        || $serverToken === ''
        || preg_match('/^[A-Za-z0-9._~-]{1,64}$/D', $username) !== 1
        || strlen($password) < 32
        || preg_match('/[\r\n\0]/', $password) === 1) {
        throw new RelayRejected('Postmark webhook configuration is incomplete.');
    }

    $publicUrl = rtrim($publicUrl, '/');
    $webhookUrl = $publicUrl.'/v1/postmark/inbound';
    $resolved = (new PublicHttpsUrl)->resolve($webhookUrl);
    $parts = parse_url($webhookUrl);
    $publicParts = parse_url($publicUrl);
    $publicPath = is_array($publicParts)
        ? rtrim((string) ($publicParts['path'] ?? ''), '/')
        : '';
    $expectedPath = $publicPath.'/v1/postmark/inbound';

    if (! is_array($parts)
        || ! is_array($publicParts)
        || ($parts['path'] ?? '') !== $expectedPath
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        throw new RelayRejected('Relay public URL is invalid.');
    }

    $authenticatedWebhookUrl = sprintf(
        'https://%s:%s@%s%s',
        rawurlencode($username),
        rawurlencode($password),
        $resolved->host,
        $expectedPath,
    );
    $payload = json_encode([
        'InboundHookUrl' => $authenticatedWebhookUrl,
        'InboundSpamThreshold' => max(0, (int) (getenv('RELAY_MAXIMUM_SPAM_SCORE') ?: 5)),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $curl = curl_init();

    if (! $curl instanceof CurlHandle) {
        throw new RuntimeException('Postmark request could not be initialized.');
    }

    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.postmarkapp.com/server',
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Postmark-Server-Token: '.$serverToken,
        ],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    if (! is_string($response) || curl_errno($curl) !== CURLE_OK || $status < 200 || $status >= 300) {
        throw new RuntimeException('Postmark rejected the inbound webhook update.');
    }

    $result = json_decode($response, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($result) || ! is_string($result['InboundHookUrl'] ?? null)) {
        throw new RuntimeException('Postmark returned an invalid server response.');
    }

    fwrite(
        STDOUT,
        "Postmark inbound webhook is configured for {$publicUrl}/v1/postmark/inbound.\n",
    );
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Postmark configuration failed: {$exception->getMessage()}\n");
    exit(1);
}
