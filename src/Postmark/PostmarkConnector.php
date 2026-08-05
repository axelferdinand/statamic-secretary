<?php

namespace AxelFerdinand\StatamicSecretary\Postmark;

use AxelFerdinand\StatamicSecretary\Email\EmailConfiguration;
use AxelFerdinand\StatamicSecretary\Exceptions\PostmarkConnectionFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class PostmarkConnector
{
    public function __construct(private readonly EmailConfiguration $email) {}

    /** @return array<string, mixed> */
    public function connect(string $fromAddress, string $publicUrl, ?string $apiKey = null): array
    {
        $token = trim((string) $apiKey) ?: $this->email->postmarkToken();

        if ($token === '') {
            throw new PostmarkConnectionFailed('Postmark Server API Token is not configured.');
        }

        if (! $this->email->isPublicHttpsUrl($publicUrl)) {
            throw new PostmarkConnectionFailed('Postmark requires a public HTTPS address.');
        }

        $client = Http::baseUrl(rtrim((string) config('secretary.email.postmark.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-Postmark-Server-Token' => $token])
            ->connectTimeout(5)
            ->timeout(15);

        try {
            $serverResponse = $client->get('/server');
        } catch (ConnectionException $exception) {
            throw new PostmarkConnectionFailed('Secretary could not reach Postmark.', previous: $exception);
        }

        if (! $serverResponse->successful()) {
            throw new PostmarkConnectionFailed('Postmark rejected the Server API Token or server request.');
        }

        $server = $serverResponse->json();
        $inboundAddress = mb_strtolower(trim((string) data_get($server, 'InboundAddress')));

        if (filter_var($inboundAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new PostmarkConnectionFailed('The Postmark server does not expose a usable inbound address.');
        }

        $webhookUrl = $this->email->authenticatedWebhookUrl($publicUrl);

        if ($webhookUrl === '' || $this->email->webhookPassword() === '') {
            throw new PostmarkConnectionFailed('Secretary could not create secure webhook credentials.');
        }

        try {
            $updateResponse = $client->put('/server', [
                'InboundHookUrl' => $webhookUrl,
                'InboundSpamThreshold' => max(0, (int) config('secretary.email.max_spam_score', 5)),
            ]);
        } catch (ConnectionException $exception) {
            throw new PostmarkConnectionFailed('Secretary could not update the Postmark webhook.', previous: $exception);
        } catch (Throwable $exception) {
            throw new PostmarkConnectionFailed('Secretary could not complete the Postmark connection.', previous: $exception);
        }

        if (! $updateResponse->successful()) {
            throw new PostmarkConnectionFailed('Postmark rejected the inbound webhook configuration.');
        }

        $settings = [
            ...$this->email->stored(),
            ...($apiKey ? ['api_key' => $token] : []),
            'from_address' => mb_strtolower(trim($fromAddress)),
            'from_name' => (string) config('secretary.email.from_name', 'Secretary'),
            'inbound_address' => $inboundAddress,
            'server_id' => data_get($server, 'ID'),
            'server_name' => data_get($server, 'Name'),
            'delivery_type' => data_get($server, 'DeliveryType'),
            'webhook_endpoint' => $this->email->webhookEndpoint($publicUrl),
            'webhook_credentials_fingerprint' => $this->email->webhookCredentialsFingerprint(),
            'connected_at' => now()->toIso8601String(),
            'forwarding_confirmed_at' => null,
        ];

        $this->email->store($settings);

        return $settings;
    }
}
