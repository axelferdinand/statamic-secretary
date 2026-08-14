<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransportResponse;
use AxelFerdinand\StatamicSecretaryRelay\CurlHttpTransport;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Data\PairingCodeNotice;
use AxelFerdinand\StatamicSecretaryRelay\Data\SelectionNotice;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\LandingPage;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkMailTransport;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkPairingCodeTransport;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkSelectionTransport;
use AxelFerdinand\StatamicSecretaryRelay\Security\PublicHttpsUrl;
use PHPUnit\Framework\TestCase;

class HostedRelayHttpSecurityTest extends TestCase
{
    public function test_landing_page_renders_english_and_norwegian_without_booting_the_relay_store(): void
    {
        $landing = new LandingPage;

        $english = $landing->render('en');
        $norwegian = $landing->render('nb');

        $this->assertSame(200, $english->status);
        $this->assertSame('text/html; charset=UTF-8', $english->headers['Content-Type']);
        $this->assertSame('en', $english->headers['Content-Language']);
        $this->assertStringContainsString('<html lang="en"', $english->body);
        $this->assertStringContainsString('Your Statamic site', $english->body);
        $this->assertStringContainsString('secretary@statamic.no', $english->body);
        $this->assertStringContainsString('https://statamic.com/addons/prototypen/secretary', $english->body);
        $this->assertStringNotContainsString('https://statamic.com/marketplace', $english->body);
        $this->assertStringContainsString('data-demo', $english->body);
        $this->assertStringContainsString('Speak human. In your language.', $english->body);
        $this->assertStringContainsString('<title>Secretary for Statamic', $english->body);
        $this->assertStringContainsString('<strong>Secretary</strong> for Statamic', $english->body);
        $this->assertStringNotContainsString('Statamic Secretary', $english->body);

        $this->assertSame(200, $norwegian->status);
        $this->assertSame('nb', $norwegian->headers['Content-Language']);
        $this->assertStringContainsString('<html lang="nb"', $norwegian->body);
        $this->assertStringContainsString('Statamic-siden din', $norwegian->body);
        $this->assertStringContainsString('Snakk menneske. På ditt språk.', $norwegian->body);
        $this->assertStringContainsString('lang="en"', $norwegian->body);
        $this->assertStringNotContainsString('Statamic Secretary', $norwegian->body);
    }

    public function test_landing_page_csp_allows_only_its_own_assets_and_forbids_forms_and_frames(): void
    {
        $result = (new LandingPage(''))->render('en');
        $policy = $result->headers['Content-Security-Policy'];

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("script-src 'self'", $policy);
        $this->assertStringContainsString("style-src 'self'", $policy);
        $this->assertStringContainsString("connect-src 'none'", $policy);
        $this->assertStringContainsString("form-action 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringNotContainsString("'unsafe-inline'", $policy);
        $this->assertStringNotContainsString('googletagmanager.com', $policy);
        $this->assertStringNotContainsString('google-analytics.com', $policy);
    }

    public function test_landing_page_exposes_google_analytics_only_when_a_valid_measurement_id_is_configured(): void
    {
        $disabled = (new LandingPage('not-a-measurement-id'))->render('en');
        $enabled = (new LandingPage('G-ABC1234567'))->render('en');

        $this->assertStringNotContainsString('google-analytics-id', $disabled->body);
        $this->assertStringNotContainsString('data-consent-manager', $disabled->body);

        $this->assertStringContainsString(
            '<meta name="google-analytics-id" content="G-ABC1234567">',
            $enabled->body,
        );
        $this->assertStringContainsString('data-consent-manager', $enabled->body);
        $this->assertStringContainsString('data-consent-accept', $enabled->body);
        $this->assertStringContainsString('data-consent-decline', $enabled->body);
        $this->assertStringContainsString('data-consent-open', $enabled->body);
        $this->assertStringContainsString(
            "script-src 'self' https://www.googletagmanager.com",
            $enabled->headers['Content-Security-Policy'],
        );
        $this->assertStringContainsString(
            'https://www.google-analytics.com',
            $enabled->headers['Content-Security-Policy'],
        );
        $this->assertStringNotContainsString("'unsafe-inline'", $enabled->headers['Content-Security-Policy']);
    }

    public function test_landing_pages_have_complete_indexable_metadata_and_relevant_schema(): void
    {
        $english = (new LandingPage(''))->render('en');
        $norwegian = (new LandingPage(''))->render('nb');

        foreach ([$english->body, $norwegian->body] as $body) {
            $this->assertSame(1, substr_count($body, '<h1'));
            $this->assertStringContainsString('<meta name="description"', $body);
            $this->assertStringContainsString('<link rel="canonical"', $body);
            $this->assertStringContainsString('property="og:site_name"', $body);
            $this->assertStringContainsString('property="og:image:alt"', $body);
            $this->assertStringContainsString('name="twitter:title"', $body);
            $this->assertStringContainsString('type="application/ld+json"', $body);
            $this->assertStringContainsString('"@type":"SoftwareApplication"', $body);
            $this->assertStringContainsString('"@type":"FAQPage"', $body);
            $this->assertStringContainsString('"@type":"Organization"', $body);
        }

        $this->assertStringContainsString(
            '<link rel="canonical" href="https://secretary.statamic.no/">',
            $english->body,
        );
        $this->assertStringContainsString(
            '<link rel="canonical" href="https://secretary.statamic.no/no">',
            $norwegian->body,
        );
        $this->assertStringContainsString('/assets/statamic-secretary-og.png', $english->body);
    }

    public function test_privacy_pages_describe_the_actual_consent_implementation(): void
    {
        $landing = new LandingPage('G-ABC1234567');
        $english = $landing->privacy('en');
        $norwegian = $landing->privacy('nb');

        $this->assertSame(200, $english->status);
        $this->assertSame(200, $norwegian->status);
        $this->assertSame(1, substr_count($english->body, '<h1'));
        $this->assertSame(1, substr_count($norwegian->body, '<h1'));
        $this->assertStringContainsString('<title>Privacy – Secretary for Statamic</title>', $english->body);
        $this->assertStringContainsString(
            '<link rel="canonical" href="https://secretary.statamic.no/privacy">',
            $english->body,
        );
        $this->assertStringContainsString(
            '<link rel="canonical" href="https://secretary.statamic.no/no/personvern">',
            $norwegian->body,
        );
        $this->assertStringContainsString('Google Analytics 4', $english->body);
        $this->assertStringContainsString('_ga', $english->body);
        $this->assertStringContainsString('data-consent-open', $english->body);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $english->body);
    }

    public function test_robots_sitemap_and_html_not_found_responses_are_search_engine_safe(): void
    {
        $landing = new LandingPage('');
        $robots = $landing->robots();
        $sitemap = $landing->sitemap();
        $notFound = $landing->notFound('en');

        $this->assertSame(200, $robots->status);
        $this->assertSame('text/plain; charset=UTF-8', $robots->headers['Content-Type']);
        $this->assertStringContainsString('Sitemap: https://secretary.statamic.no/sitemap.xml', $robots->body);
        $this->assertStringContainsString('Disallow: /v1/', $robots->body);

        $this->assertSame(200, $sitemap->status);
        $this->assertSame('application/xml; charset=UTF-8', $sitemap->headers['Content-Type']);
        $this->assertStringContainsString('<loc>https://secretary.statamic.no/</loc>', $sitemap->body);
        $this->assertStringContainsString('<loc>https://secretary.statamic.no/privacy</loc>', $sitemap->body);
        $this->assertStringContainsString('<loc>https://secretary.statamic.no/no/personvern</loc>', $sitemap->body);

        $this->assertSame(404, $notFound->status);
        $this->assertSame('noindex, nofollow', $notFound->headers['X-Robots-Tag']);
        $this->assertSame(1, substr_count($notFound->body, '<h1'));
        $this->assertStringContainsString('Page not found', $notFound->body);
    }

    public function test_consent_script_loads_google_only_after_acceptance_and_supports_withdrawal(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/relay/public/assets/secretary.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('statamic-secretary-analytics-consent-v1', $script);
        $this->assertStringContainsString("document.createElement('script')", $script);
        $this->assertStringContainsString('www.googletagmanager.com/gtag/js', $script);
        $this->assertStringContainsString('data-consent-accept', $script);
        $this->assertStringContainsString('data-consent-decline', $script);
        $this->assertStringContainsString('data-consent-open', $script);
        $this->assertStringContainsString('clearAnalyticsCookies', $script);
        $this->assertStringNotContainsString('G-J7C3EREJW2', $script);
    }

    public function test_production_htaccess_canonicalizes_http_and_www_requests(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 2).'/relay/public/.htaccess');

        $this->assertIsString($configuration);
        $this->assertStringContainsString('%{HTTPS} !=on', $configuration);
        $this->assertStringContainsString('%{HTTP_HOST} !^secretary\\.statamic\\.no$', $configuration);
        $this->assertStringContainsString(
            'https://secretary.statamic.no%{REQUEST_URI}',
            $configuration,
        );
        $this->assertStringContainsString('[R=301,L,NE]', $configuration);
        $this->assertStringContainsString('error_log|php\\.ini', $configuration);
        $this->assertStringContainsString('Require all denied', $configuration);
    }

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
        $this->assertSame('Choose a site for Secretary', $payload['Subject']);
        $this->assertStringContainsString('was not sent to a site', $payload['TextBody']);
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
        $this->assertSame('Confirm Secretary', $payload['Subject']);
        $this->assertStringContainsString('One-time code:', $payload['TextBody']);
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
