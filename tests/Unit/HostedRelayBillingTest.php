<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransportResponse;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\PublicAliasProvisioner;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SubscriptionGateway;
use AxelFerdinand\StatamicSecretaryRelay\Data\BillingCheckout;
use AxelFerdinand\StatamicSecretaryRelay\Data\BillingUpdate;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Data\PairingOutcome;
use AxelFerdinand\StatamicSecretaryRelay\PairingService;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteRelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteSchema;
use AxelFerdinand\StatamicSecretaryRelay\RelayAddress;
use AxelFerdinand\StatamicSecretaryRelay\Security\PublicHttpsUrl;
use AxelFerdinand\StatamicSecretaryRelay\StripeSubscriptionGateway;
use AxelFerdinand\StatamicSecretaryRelay\SubscriptionService;
use PDO;
use PHPUnit\Framework\TestCase;

class HostedRelayBillingTest extends TestCase
{
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
