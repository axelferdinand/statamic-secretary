<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretaryRelay\BillingNoticeService;
use AxelFerdinand\StatamicSecretaryRelay\Bootstrap\RelayFactory;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\BillingNoticeTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransportResponse;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\MailTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\PairingCodeTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\PublicAliasProvisioner;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SelectionTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SiteTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SubscriptionGateway;
use AxelFerdinand\StatamicSecretaryRelay\Data\BillingCheckout;
use AxelFerdinand\StatamicSecretaryRelay\Data\BillingNotice;
use AxelFerdinand\StatamicSecretaryRelay\Data\BillingUpdate;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundMessage;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Data\OutboundReply;
use AxelFerdinand\StatamicSecretaryRelay\Data\PairingCodeNotice;
use AxelFerdinand\StatamicSecretaryRelay\Data\PairingOutcome;
use AxelFerdinand\StatamicSecretaryRelay\Data\SelectionNotice;
use AxelFerdinand\StatamicSecretaryRelay\Data\SiteDeliveryResult;
use AxelFerdinand\StatamicSecretaryRelay\HostedRelayApplication;
use AxelFerdinand\StatamicSecretaryRelay\InboundRouter;
use AxelFerdinand\StatamicSecretaryRelay\PairingService;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteRelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteSchema;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkBillingNoticeTransport;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkInboundAdapter;
use AxelFerdinand\StatamicSecretaryRelay\RelayAddress;
use AxelFerdinand\StatamicSecretaryRelay\ReplyService;
use AxelFerdinand\StatamicSecretaryRelay\Security\BasicAuth;
use AxelFerdinand\StatamicSecretaryRelay\Security\PublicHttpsUrl;
use AxelFerdinand\StatamicSecretaryRelay\SelectionService;
use AxelFerdinand\StatamicSecretaryRelay\StripeSubscriptionGateway;
use AxelFerdinand\StatamicSecretaryRelay\SubscriptionService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class HostedRelayBillingTest extends TestCase
{
    public function test_factory_wires_billing_without_shifting_postmark_limits(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'secretary-relay-factory-');
        $this->assertIsString($databasePath);
        $environment = [
            'RELAY_DATABASE_PATH' => $databasePath,
            'RELAY_DATABASE_KEY' => base64_encode(str_repeat('k', 32)),
            'RELAY_POSTMARK_SERVER_TOKEN' => 'test-postmark-token',
            'RELAY_POSTMARK_WEBHOOK_USER' => 'testuser1',
            'RELAY_POSTMARK_WEBHOOK_PASSWORD' => str_repeat('p', 32),
            'RELAY_FRIENDLY_ALIASES_ENABLED' => 'false',
            'RELAY_STRIPE_SECRET_KEY' => 'sk_test_'.str_repeat('a', 32),
            'RELAY_STRIPE_PRICE_ID' => 'price_'.str_repeat('b', 24),
            'RELAY_STRIPE_WEBHOOK_SECRET' => 'whsec_'.str_repeat('c', 32),
        ];

        foreach ($environment as $key => $value) {
            putenv($key.'='.$value);
        }

        try {
            $application = (new RelayFactory)->application();
            $this->assertInstanceOf(HostedRelayApplication::class, $application);

            $inbound = (new ReflectionProperty($application, 'inbound'))->getValue($application);
            $postmark = (new ReflectionProperty($application, 'postmark'))->getValue($application);
            $billingNotices = (new ReflectionProperty($application, 'billingNotices'))->getValue($application);

            $this->assertInstanceOf(InboundRouter::class, $inbound);
            $this->assertTrue((new ReflectionProperty($inbound, 'subscriptionRequired'))->getValue($inbound));
            $this->assertInstanceOf(PostmarkInboundAdapter::class, $postmark);
            $this->assertSame(20000, (new ReflectionProperty($postmark, 'maximumCharacters'))->getValue($postmark));
            $this->assertSame(4, (new ReflectionProperty($postmark, 'maximumAttachments'))->getValue($postmark));
            $this->assertSame(8_000_000, (new ReflectionProperty($postmark, 'maximumAttachmentBytes'))->getValue($postmark));
            $this->assertSame(16_000_000, (new ReflectionProperty($postmark, 'maximumTotalAttachmentBytes'))->getValue($postmark));
            $this->assertInstanceOf(BillingNoticeService::class, $billingNotices);
        } finally {
            foreach (array_keys($environment) as $key) {
                putenv($key);
            }

            foreach ([$databasePath, $databasePath.'-shm', $databasePath.'-wal'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_unpaid_pairing_returns_checkout_without_relay_credentials(): void
    {
        $store = $this->store();
        $installation = $this->installation('pending');
        $store->saveInstallation($installation);
        $subscriptions = new SubscriptionService($store, new BillingSubscriptionGateway);
        $pairings = new PairingService(
            $store,
            new RelayAddress('secretary@statamic.no'),
            new PublicHttpsUrl,
            subscriptions: $subscriptions,
        );

        $response = $pairings->response(new PairingOutcome($installation, false));

        $this->assertSame('payment_required', $response['status']);
        $this->assertSame(4900, $response['price']['amount']);
        $this->assertArrayNotHasKey('signing_secret', $response);
        $this->assertArrayNotHasKey('route_token', $response);
        $this->assertArrayNotHasKey('address', $response);
        $this->assertFalse($store->installationById($installation->id)?->hasRelayAccess(true));

        $this->assertTrue($store->applyBillingEvent(
            'evt_'.str_repeat('a', 24),
            $installation->id,
            'sub_'.str_repeat('b', 24),
            'cus_'.str_repeat('c', 24),
            'active',
            time() + 31_536_000,
        ));
        $this->assertFalse($store->applyBillingEvent(
            'evt_'.str_repeat('a', 24),
            $installation->id,
            'sub_'.str_repeat('b', 24),
            'cus_'.str_repeat('c', 24),
            'active',
            time() + 31_536_000,
        ));

        $active = $store->installationById($installation->id);
        $this->assertNotNull($active);
        $this->assertTrue($active->hasRelayAccess(true));
        $this->assertArrayHasKey(
            'signing_secret',
            $pairings->response(new PairingOutcome($active, true)),
        );
    }

    public function test_complimentary_demo_is_entitled_without_a_subscription(): void
    {
        $this->assertTrue($this->installation('complimentary')->hasRelayAccess(true));
        $this->assertFalse($this->installation('beta')->hasRelayAccess(true));
        $this->assertTrue($this->installation('beta')->hasRelayAccess(false));
    }

    public function test_alias_and_credentials_are_withheld_until_payment_is_confirmed(): void
    {
        $store = $this->store();
        $aliases = new BillingAliasProvisioner;
        $pairings = new PairingService(
            $store,
            new RelayAddress('secretary@statamic.no'),
            new PublicHttpsUrl(static fn (): array => ['8.8.8.8']),
            $aliases,
            new SubscriptionService($store, new BillingSubscriptionGateway),
        );
        $issued = $pairings->issue('Live site', ['owner@example.com']);
        $body = json_encode([
            'version' => 1,
            'pairing_code' => $issued->code,
            'claim_id' => 'pci_'.str_repeat('a', 22),
            'webhook_url' => 'https://live.example.com/_secretary/webhooks/relay/inbound',
        ], JSON_THROW_ON_ERROR);

        $pending = $pairings->claim($body);
        $pendingResponse = $pairings->response($pending);

        $this->assertSame('payment_required', $pendingResponse['status']);
        $this->assertSame([], $aliases->installations);
        $this->assertArrayNotHasKey('signing_secret', $pendingResponse);

        $this->assertTrue($store->applyBillingEvent(
            'evt_'.str_repeat('d', 24),
            $pending->installation->id,
            'sub_'.str_repeat('e', 24),
            'cus_'.str_repeat('f', 24),
            'active',
            time() + 31_536_000,
        ));

        $connected = $pairings->claim($body);
        $connectedResponse = $pairings->response($connected);

        $this->assertCount(1, $aliases->installations);
        $this->assertSame('already_paired', $connectedResponse['status']);
        $this->assertArrayHasKey('signing_secret', $connectedResponse);
        $this->assertSame('live.example.com@statamic.no', $connectedResponse['address']);
    }

    public function test_stripe_checkout_and_signed_webhook_use_the_expected_contract(): void
    {
        $checkoutPayload = json_encode([
            'id' => 'cs_test_'.str_repeat('a', 24),
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_'.str_repeat('a', 24),
            'expires_at' => time() + 1800,
        ], JSON_THROW_ON_ERROR);
        $http = new BillingHttpTransport(new HttpTransportResponse(200, $checkoutPayload));
        $secret = 'whsec_'.str_repeat('s', 32);
        $gateway = new StripeSubscriptionGateway(
            $http,
            'sk_test_'.str_repeat('k', 32),
            'price_'.str_repeat('p', 24),
            $secret,
        );

        $checkout = $gateway->createCheckout($this->installation('pending'));

        $this->assertStringStartsWith('cs_test_', $checkout->id);
        $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $http->requests[0]['url']);
        $this->assertStringContainsString('mode=subscription', $http->requests[0]['body']);
        $this->assertStringContainsString('client_reference_id=si_', $http->requests[0]['body']);
        $this->assertStringStartsWith('Bearer sk_test_', $http->requests[0]['headers']['Authorization']);

        $body = json_encode([
            'id' => 'evt_'.str_repeat('e', 24),
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_'.str_repeat('b', 24),
                'customer' => 'cus_'.str_repeat('c', 24),
                'status' => 'active',
                'current_period_end' => time() + 31_536_000,
                'metadata' => ['installation_id' => $this->installation()->id],
            ]],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        $update = $gateway->webhook(
            ['Stripe-Signature' => "t={$timestamp},v1={$signature}"],
            $body,
        );

        $this->assertInstanceOf(BillingUpdate::class, $update);
        $this->assertSame('active', $update->status);
        $this->assertSame($this->installation()->id, $update->installationId);
    }

    public function test_pending_inbound_sends_one_activation_email_without_reaching_the_site(): void
    {
        $store = $this->store();
        $installation = $this->installation('pending');
        $store->saveInstallation($installation);
        $address = new RelayAddress('secretary@statamic.no');
        $site = new BillingSiteTransport;
        $notices = new BillingNoticeCollector;
        $subscriptions = new SubscriptionService($store, new BillingSubscriptionGateway);
        $application = new HostedRelayApplication(
            new BasicAuth('postmark_webhook', str_repeat('p', 32)),
            new PostmarkInboundAdapter('secretary@statamic.no'),
            new InboundRouter($store, $site, $address, subscriptionRequired: true),
            new ReplyService($store, new BillingMailTransport, $address, subscriptionRequired: true),
            new SelectionService(
                $store,
                $store,
                new BillingSelectionTransport,
                $address,
                subscriptionRequired: true,
            ),
            new PairingService(
                $store,
                $address,
                new PublicHttpsUrl(static fn (): array => ['8.8.8.8']),
                subscriptions: $subscriptions,
            ),
            new BillingPairingCodeTransport,
            billingNotices: new BillingNoticeService($store, $store, $subscriptions, $notices),
        );
        $body = json_encode([
            'MessageID' => 'pending-inbound-1',
            'MailboxHash' => $installation->routeToken,
            'Subject' => 'Update the homepage',
            'StrippedTextReply' => 'Make the introduction clearer.',
            'TextBody' => 'Make the introduction clearer.',
            'FromFull' => ['Email' => 'owner@example.com'],
            'Headers' => [
                ['Name' => 'X-Spam-Score', 'Value' => '-0.1'],
                ['Name' => 'X-Spam-Tests', 'Value' => 'DKIM_VALID_AU,SPF_PASS'],
                ['Name' => 'Message-ID', 'Value' => '<pending-inbound-1@example.com>'],
            ],
            'Attachments' => [],
        ], JSON_THROW_ON_ERROR);
        $headers = [
            'Authorization' => 'Basic '.base64_encode('postmark_webhook:'.str_repeat('p', 32)),
            'Content-Type' => 'application/json',
        ];

        $first = $application->postmarkInbound($headers, $body);
        $duplicate = $application->postmarkInbound($headers, $body);

        $this->assertSame(200, $first->status);
        $this->assertSame(['accepted' => false, 'status' => 'payment_required'], json_decode($first->body, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(200, $duplicate->status);
        $this->assertSame(['accepted' => false, 'status' => 'payment_required'], json_decode($duplicate->body, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame([], $site->deliveries);
        $this->assertCount(1, $notices->notices);
        $this->assertSame('owner@example.com', $notices->notices[0]->recipient);
        $this->assertSame('Example site', $notices->notices[0]->siteLabel);
        $this->assertStringStartsWith('https://checkout.stripe.com/', $notices->notices[0]->checkoutUrl);
        $this->assertSame('<pending-inbound-1@example.com>', $notices->notices[0]->inReplyTo);

        $unknownBody = str_replace(
            ['pending-inbound-1', 'owner@example.com'],
            ['unknown-inbound-1', 'stranger@example.com'],
            $body,
        );
        $unknown = $application->postmarkInbound($headers, $unknownBody);

        $this->assertSame(['accepted' => false, 'status' => 'rejected'], json_decode($unknown->body, true, flags: JSON_THROW_ON_ERROR));
        $this->assertCount(1, $notices->notices);
        $this->assertSame([], $site->deliveries);
    }

    public function test_activation_email_is_english_and_links_to_secure_checkout(): void
    {
        $http = new BillingHttpTransport(new HttpTransportResponse(200, json_encode([
            'ErrorCode' => 0,
            'MessageID' => 'postmark-billing-notice-1',
        ], JSON_THROW_ON_ERROR)));
        $transport = new PostmarkBillingNoticeTransport(
            $http,
            'postmark-token',
            'secretary@statamic.no',
        );

        $providerId = $transport->send(new BillingNotice(
            'pending-inbound-1',
            $this->installation()->id,
            'owner@example.com',
            'Example <site>',
            'https://checkout.stripe.com/c/pay/cs_test_'.str_repeat('a', 24),
            '<pending-inbound-1@example.com>',
        ));

        $payload = json_decode($http->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('postmark-billing-notice-1', $providerId);
        $this->assertSame('Activate Relay for Secretary', $payload['Subject']);
        $this->assertStringContainsString('USD 49/year', $payload['TextBody']);
        $this->assertStringContainsString('https://checkout.stripe.com/', $payload['TextBody']);
        $this->assertStringContainsString('Nothing was changed or published', $payload['TextBody']);
        $this->assertStringNotContainsString('Aktiver', $payload['TextBody']);
        $this->assertStringContainsString('Example &lt;site&gt;', $payload['HtmlBody']);
        $this->assertSame('In-Reply-To', $payload['Headers'][0]['Name']);
        $this->assertSame('<pending-inbound-1@example.com>', $payload['Headers'][0]['Value']);
    }

    private function store(): SqliteRelayStore
    {
        $pdo = new PDO('sqlite::memory:');
        SqliteSchema::migrate($pdo);

        return new SqliteRelayStore($pdo, random_bytes(32));
    }

    private function installation(string $billingStatus = 'pending'): Installation
    {
        return new Installation(
            'si_'.str_repeat('a', 32),
            'r'.str_repeat('a', 25),
            'https://site.example.com/_secretary/webhooks/relay/inbound',
            str_repeat('s', 32),
            ['owner@example.com'],
            true,
            'Example site',
            billingStatus: $billingStatus,
        );
    }
}

final class BillingSubscriptionGateway implements SubscriptionGateway
{
    public function createCheckout(Installation $installation): BillingCheckout
    {
        return new BillingCheckout(
            'cs_test_'.str_repeat('a', 24),
            'https://checkout.stripe.com/c/pay/cs_test_'.str_repeat('a', 24),
            time() + 1800,
        );
    }

    public function webhook(array $headers, string $body): ?BillingUpdate
    {
        return null;
    }
}

final class BillingHttpTransport implements HttpTransport
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

final class BillingAliasProvisioner implements PublicAliasProvisioner
{
    /** @var array<int, Installation> */
    public array $installations = [];

    public function provision(Installation $installation): void
    {
        $this->installations[] = $installation;
    }
}

final class BillingNoticeCollector implements BillingNoticeTransport
{
    /** @var array<int, BillingNotice> */
    public array $notices = [];

    public function send(BillingNotice $notice): string
    {
        $this->notices[] = $notice;

        return 'postmark-billing-notice-'.count($this->notices);
    }
}

final class BillingSiteTransport implements SiteTransport
{
    /** @var array<int, InboundMessage> */
    public array $deliveries = [];

    public function deliver(Installation $installation, InboundMessage $message, ?string $conversationToken): SiteDeliveryResult
    {
        $this->deliveries[] = $message;

        return new SiteDeliveryResult('c'.str_repeat('a', 25));
    }
}

final class BillingMailTransport implements MailTransport
{
    public function send(OutboundReply $reply): string
    {
        return 'unused-mail';
    }
}

final class BillingSelectionTransport implements SelectionTransport
{
    public function send(SelectionNotice $notice): string
    {
        return 'unused-selection';
    }
}

final class BillingPairingCodeTransport implements PairingCodeTransport
{
    public function send(PairingCodeNotice $notice): string
    {
        return 'unused-pairing-code';
    }
}
