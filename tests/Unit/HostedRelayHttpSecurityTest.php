<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransportResponse;
use AxelFerdinand\StatamicSecretaryRelay\CurlHttpTransport;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Data\PairingCodeNotice;
use AxelFerdinand\StatamicSecretaryRelay\Data\SelectionNotice;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkMailTransport;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkPairingCodeTransport;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkSelectionTransport;
use AxelFerdinand\StatamicSecretaryRelay\Security\PublicHttpsUrl;
use PHPUnit\Framework\TestCase;

class HostedRelayHttpSecurityTest extends TestCase
{
    public function test_public_https_policy_resolves_and_returns_only_public_addresses(): void
    {
        $policy = new PublicHttpsUrl(
            static fn (string $host): array => $host === 'site.example.com'
                ? ['8.8.8.8', '2606:4700:4700::1111']
                : [],
        );

        $resolved = $policy->resolve('https://site.example.com/_secretary/webhooks/relay/inbound');

        $this->assertSame('site.example.com', $resolved->host);
        $this->assertSame(443, $resolved->port);
        $this->assertSame(['8.8.8.8', '2606:4700:4700::1111'], $resolved->addresses);
    }

    public function test_public_https_policy_rejects_private_mixed_unresolved_and_malformed_destinations(): void
    {
        $private = new PublicHttpsUrl(static fn (): array => ['127.0.0.1']);
        $mixed = new PublicHttpsUrl(static fn (): array => ['8.8.8.8', '10.0.0.1']);
        $empty = new PublicHttpsUrl(static fn (): array => []);
        $invalid = [
            [$private, 'https://site.example.com/_secretary/webhooks/relay/inbound'],
            [$mixed, 'https://site.example.com/_secretary/webhooks/relay/inbound'],
            [$empty, 'https://site.example.com/_secretary/webhooks/relay/inbound'],
            [$private, 'http://site.example.com/_secretary/webhooks/relay/inbound'],
            [$private, 'https://user:pass@site.example.com/_secretary/webhooks/relay/inbound'],
            [$private, 'https://site.example.com:8443/_secretary/webhooks/relay/inbound'],
            [$private, 'https://127.0.0.1/_secretary/webhooks/relay/inbound'],
        ];

        foreach ($invalid as [$policy, $url]) {
            try {
                $policy->resolve($url);
                $this->fail('An unsafe HTTP destination was accepted.');
            } catch (RelayRejected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_curl_transport_rejects_private_destinations_and_header_smuggling_before_network_io(): void
    {
        $privateTransport = new CurlHttpTransport(new PublicHttpsUrl(static fn (): array => ['10.0.0.1']));

        try {
            $privateTransport->post('https://site.example.com/hook', '{}', ['Content-Type' => 'application/json']);
            $this->fail('A private destination reached the HTTP client.');
        } catch (RelayRejected $exception) {
            $this->assertSame('Relay HTTP destination is not public.', $exception->getMessage());
        }

        $publicTransport = new CurlHttpTransport(new PublicHttpsUrl(static fn (): array => ['8.8.8.8']));

        foreach ([
            ['Host' => 'attacker.example.com'],
            ['X-Test' => "safe\r\nHost: attacker.example.com"],
            ['Content-Length' => '1'],
        ] as $headers) {
            try {
                $publicTransport->post('https://site.example.com/hook', '{}', $headers);
                $this->fail('An unsafe HTTP header reached the network.');
            } catch (RelayRejected $exception) {
                $this->assertSame('Relay HTTP headers are invalid.', $exception->getMessage());
            }
        }
    }

    public function test_installation_webhooks_are_fixed_to_the_addon_endpoint(): void
    {
        foreach ([
            'https://site.example.com/other',
            'https://site.example.com:8443/_secretary/webhooks/relay/inbound',
            'https://site.example.com/_secretary/webhooks/relay/inbound?target=other',
        ] as $url) {
            try {
                new Installation(
                    'si_'.str_repeat('a', 32),
                    'r'.str_repeat('a', 25),
                    $url,
                    str_repeat('s', 32),
                    ['editor@example.com'],
                );
                $this->fail('An unexpected installation webhook was accepted.');
            } catch (RelayRejected $exception) {
                $this->assertSame('Installation configuration is invalid.', $exception->getMessage());
            }
        }
    }

    public function test_postmark_token_can_only_be_sent_to_the_official_email_endpoint(): void
    {
        $http = new class implements HttpTransport
        {
            public function post(string $url, string $body, array $headers): HttpTransportResponse
            {
                throw new \RuntimeException('Network should not be reached.');
            }
        };

        foreach ([
            'https://attacker.example.com/email',
            'https://api.postmarkapp.com/other',
            'https://api.postmarkapp.com:8443/email',
            'https://api.postmarkapp.com/email?copy=1',
        ] as $endpoint) {
            try {
                new PostmarkMailTransport(
                    $http,
                    'server-token',
                    'secretary@statamic.no',
                    endpoint: $endpoint,
                );
                $this->fail('A Postmark token exfiltration endpoint was accepted.');
            } catch (RelayRejected $exception) {
                $this->assertSame('Postmark mail transport configuration is invalid.', $exception->getMessage());
            }
        }
    }

    public function test_selection_notice_contains_only_site_aliases_and_keeps_the_postmark_token_out_of_the_body(): void
    {
        $http = new SelectionHttpTransport(new HttpTransportResponse(200, json_encode([
            'ErrorCode' => 0,
            'Message' => 'OK',
            'MessageID' => 'postmark-selection-1',
        ], JSON_THROW_ON_ERROR)));
        $token = 'selection-server-token';
        $transport = new PostmarkSelectionTransport(
            $http,
            $token,
            'secretary@statamic.no',
        );
        $notice = new SelectionNotice(
            'postmark-inbound-1',
            'editor@example.com',
            [
                ['label' => 'Site A', 'address' => 'secretary+r'.str_repeat('a', 25).'@statamic.no'],
                ['label' => 'Site B', 'address' => 'secretary+r'.str_repeat('b', 25).'@statamic.no'],
            ],
            '<postmark-inbound-1@example.com>',
        );

        $providerMessageId = $transport->send($notice);

        $this->assertSame('postmark-selection-1', $providerMessageId);
        $this->assertCount(1, $http->requests);
        $request = $http->requests[0];
        $this->assertSame('https://api.postmarkapp.com/email', $request['url']);
        $this->assertSame($token, $request['headers']['X-Postmark-Server-Token']);
        $this->assertStringNotContainsString($token, $request['body']);
        $payload = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('editor@example.com', $payload['To']);
        $this->assertStringContainsString($notice->candidates[0]['address'], $payload['TextBody']);
        $this->assertStringContainsString($notice->candidates[1]['address'], $payload['TextBody']);
        $this->assertStringContainsString('ikke sendt til noe nettsted', $payload['TextBody']);
        $this->assertArrayNotHasKey('HtmlBody', $payload);
    }

    public function test_pairing_code_is_sent_only_to_the_verified_recipient_and_never_returned_to_the_addon_request(): void
    {
        $http = new SelectionHttpTransport(new HttpTransportResponse(200, json_encode([
            'ErrorCode' => 0,
            'Message' => 'OK',
            'MessageID' => 'postmark-pairing-1',
        ], JSON_THROW_ON_ERROR)));
        $token = 'pairing-server-token';
        $transport = new PostmarkPairingCodeTransport(
            $http,
            $token,
            'secretary@statamic.no',
        );
        $notice = new PairingCodeNotice(
            'owner@example.com',
            'Kunde X',
            'pc_'.str_repeat('a', 43),
            time() + 900,
        );

        $providerMessageId = $transport->send($notice);

        $this->assertSame('postmark-pairing-1', $providerMessageId);
        $this->assertCount(1, $http->requests);
        $request = $http->requests[0];
        $this->assertSame('https://api.postmarkapp.com/email', $request['url']);
        $this->assertSame($token, $request['headers']['X-Postmark-Server-Token']);
        $this->assertStringNotContainsString($token, $request['body']);
        $payload = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('owner@example.com', $payload['To']);
        $this->assertSame('Bekreft Statamic Secretary', $payload['Subject']);
        $this->assertStringContainsString($notice->code, $payload['TextBody']);
        $this->assertStringContainsString('Kunde X', $payload['TextBody']);
        $this->assertArrayNotHasKey('HtmlBody', $payload);
        $this->assertArrayNotHasKey('ReplyTo', $payload);
    }
}

final class SelectionHttpTransport implements HttpTransport
{
    /** @var array<int, array{url: string, body: string, headers: array<string, string>}> */
    public array $requests = [];

    public function __construct(private readonly HttpTransportResponse $response) {}

    public function post(string $url, string $body, array $headers): HttpTransportResponse
    {
        $this->requests[] = compact('url', 'body', 'headers');

        return $this->response;
    }
}
