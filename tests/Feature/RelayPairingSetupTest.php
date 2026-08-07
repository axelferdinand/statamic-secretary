<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Jobs\ProcessInboundEmail;
use AxelFerdinand\StatamicSecretary\Models\Setting;
use AxelFerdinand\StatamicSecretary\Relay\RelayConfiguration;
use AxelFerdinand\StatamicSecretary\Relay\RelaySignature;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class RelayPairingSetupTest extends TestCase
{
    private const INSTALLATION_ID = 'si_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const ROUTE_TOKEN = 'raaaaaaaaaaaaaaaaaaaaaaaaa';

    private const PAIRING_CODE = 'pc_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SECRET = 'ssssssssssssssssssssssssssssssss';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('secretary.relay.pairing_enabled', true);
        config()->set('secretary.relay.base_url', 'https://secretary.statamic.no');
    }

    public function test_an_administrator_connects_the_shared_address_with_one_pairing_code(): void
    {
        $this->fakePairingSuccess();
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post('/cp/secretary/setup/relay', [
                'pairing_code' => self::PAIRING_CODE,
                'public_url' => 'https://site.example.com',
            ])
            ->assertRedirect('/cp/secretary')
            ->assertSessionHas('secretary_success');

        $settings = Setting::query()->findOrFail('relay')->value;
        $this->assertTrue($settings['enabled']);
        $this->assertSame(self::INSTALLATION_ID, $settings['installation_id']);
        $this->assertSame(self::ROUTE_TOKEN, $settings['route_token']);
        $this->assertSame($this->encodedSecret(), $settings['signing_secret']);
        $this->assertSame('site.example.com@statamic.no', $settings['address']);
        $this->assertSame('secretary+'.self::ROUTE_TOKEN.'@statamic.no', $settings['route_address']);
        $this->assertArrayNotHasKey('pending_code_fingerprint', $settings);
        $this->assertArrayNotHasKey('pending_claim_id', $settings);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://secretary.statamic.no/v1/pairings/claim'
                && $payload['version'] === 1
                && $payload['pairing_code'] === self::PAIRING_CODE
                && preg_match('/^pci_[A-Za-z0-9_-]{43}$/D', $payload['claim_id']) === 1
                && $payload['webhook_url'] === 'https://site.example.com/_secretary/webhooks/relay/inbound';
        });

        $raw = (string) DB::table('secretary_settings')->where('key', 'relay')->value('value');
        $this->assertStringNotContainsString(self::PAIRING_CODE, $raw);
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertStringNotContainsString($this->encodedSecret(), $raw);

        $configuration = app(RelayConfiguration::class);
        $this->assertTrue($configuration->connected());
        $this->assertTrue($configuration->enabled());
        $this->assertSame(self::SECRET, $configuration->secret());

        $this->actingAs($owner)
            ->get('/cp/secretary')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('statamic-secretary::Secretary')
                ->where('email_enabled', true)
                ->where('relay_setup.connected', true)
                ->where('relay_setup.address', 'site.example.com@statamic.no')
                ->where('relay_setup.route_address', 'secretary+'.self::ROUTE_TOKEN.'@statamic.no')
                ->missing('relay_setup.signing_secret')
                ->missing('relay_setup.installation_id')
                ->missing('relay_setup.route_token'));
    }

    public function test_an_administrator_can_request_a_pairing_code_for_an_authorized_statamic_user(): void
    {
        Http::fake([
            'https://secretary.statamic.no/v1/pairings/request' => Http::response([
                'accepted' => true,
                'status' => 'verification_sent',
            ], 202),
        ]);
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post('/cp/secretary/setup/relay/request-code', [
                'email' => 'OWNER@example.com',
                'public_url' => 'https://site.example.com',
            ])
            ->assertRedirect('/cp/secretary')
            ->assertSessionHas('secretary_success');

        $settings = Setting::query()->findOrFail('relay')->value;
        $this->assertSame('owner@example.com', $settings['pending_sender']);
        $this->assertSame('https://site.example.com', $settings['pending_public_url']);
        $this->assertNotEmpty($settings['verification_requested_at']);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://secretary.statamic.no/v1/pairings/request'
                && $payload === [
                    'version' => 1,
                    'email' => 'owner@example.com',
                    'label' => mb_substr(trim((string) config('app.name')), 0, 120),
                ];
        });

        $this->actingAs($owner)
            ->get('/cp/secretary')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('relay_setup.pending_sender', 'owner@example.com')
                ->where('relay_setup.pending_public_url', 'https://site.example.com')
                ->where('relay_setup.suggested_sender', 'owner@example.com')
                ->where('relay_setup.request_code_url', 'http://localhost/cp/secretary/setup/relay/request-code'));

        $this->fakePairingSuccess();
        $this->actingAs($owner)
            ->post('/cp/secretary/setup/relay', [
                'pairing_code' => self::PAIRING_CODE,
                'public_url' => 'https://site.example.com',
            ])
            ->assertRedirect('/cp/secretary')
            ->assertSessionHas('secretary_success');

        $connected = Setting::query()->findOrFail('relay')->value;
        $this->assertSame('owner@example.com', $connected['sender']);
        $this->assertSame('site.example.com@statamic.no', $connected['address']);
        $this->assertSame('secretary+'.self::ROUTE_TOKEN.'@statamic.no', $connected['route_address']);
        $this->assertArrayNotHasKey('pending_sender', $connected);
    }

    public function test_pairing_code_requests_require_a_statamic_user_with_secretary_access(): void
    {
        Http::fake();
        $owner = $this->owner();
        $blocked = User::make()->id('blocked@example.com')->email('blocked@example.com');
        $blocked->save();

        $this->from('/cp/secretary')
            ->actingAs($owner)
            ->post('/cp/secretary/setup/relay/request-code', [
                'email' => 'unknown@example.com',
                'public_url' => 'https://site.example.com',
            ])
            ->assertRedirect('/cp/secretary')
            ->assertSessionHasErrors('relay_email');

        $this->from('/cp/secretary')
            ->actingAs($owner)
            ->post('/cp/secretary/setup/relay/request-code', [
                'email' => 'blocked@example.com',
                'public_url' => 'https://site.example.com',
            ])
            ->assertRedirect('/cp/secretary')
            ->assertSessionHasErrors('relay_email');

        Http::assertNothingSent();
    }

    public function test_the_public_site_url_is_required_before_requesting_a_pairing_code(): void
    {
        Http::fake();
        $owner = $this->owner();

        $this->from('/cp/secretary')
            ->actingAs($owner)
            ->post('/cp/secretary/setup/relay/request-code', [
                'email' => 'owner@example.com',
                'public_url' => 'http://statamic-secretary.test',
            ])
            ->assertRedirect('/cp/secretary')
            ->assertSessionHasErrors('public_url');

        Http::assertNothingSent();
        $this->assertDatabaseMissing('secretary_settings', ['key' => 'relay']);
    }

    public function test_a_retry_uses_the_same_claim_id_without_storing_the_pairing_code(): void
    {
        $calls = 0;
        $claimIds = [];
        Http::fake(function (Request $request) use (&$calls, &$claimIds) {
            $calls++;
            $claimIds[] = $request->data()['claim_id'];

            return $calls === 1
                ? Http::response(['accepted' => false, 'status' => 'temporary_failure'], 503)
                : Http::response($this->pairingResponse(), 201);
        });
        $owner = $this->owner();

        $this->from('/cp/secretary')
            ->actingAs($owner)
            ->post('/cp/secretary/setup/relay', [
                'pairing_code' => self::PAIRING_CODE,
                'public_url' => 'https://site.example.com',
            ])
            ->assertRedirect('/cp/secretary')
            ->assertSessionHasErrors('relay_setup');

        $pending = Setting::query()->findOrFail('relay')->value;
        $this->assertSame(hash('sha256', self::PAIRING_CODE), $pending['pending_code_fingerprint']);
        $this->assertMatchesRegularExpression('/^pci_[A-Za-z0-9_-]{43}$/D', $pending['pending_claim_id']);
        $this->assertStringNotContainsString(
            self::PAIRING_CODE,
            (string) DB::table('secretary_settings')->where('key', 'relay')->value('value'),
        );

        $this->actingAs($owner)
            ->post('/cp/secretary/setup/relay', [
                'pairing_code' => self::PAIRING_CODE,
                'public_url' => 'https://site.example.com',
            ])
            ->assertRedirect('/cp/secretary')
            ->assertSessionHas('secretary_success');

        $this->assertSame(2, $calls);
        $this->assertSame($claimIds[0], $claimIds[1]);
        $this->assertSame($pending['pending_claim_id'], $claimIds[1]);
    }

    public function test_paid_relay_checkout_is_shown_without_storing_delivery_credentials(): void
    {
        Http::fake([
            'https://secretary.statamic.no/v1/pairings/claim' => Http::response([
                'accepted' => true,
                'status' => 'payment_required',
                'installation_id' => self::INSTALLATION_ID,
                'billing_status' => 'pending',
                'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_'.str_repeat('a', 24),
                'checkout_expires_at' => now()->addMinutes(30)->getTimestamp(),
                'price' => [
                    'amount' => 4900,
                    'currency' => 'usd',
                    'interval' => 'year',
                ],
            ], 201),
        ]);
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post('/cp/secretary/setup/relay', [
                'pairing_code' => self::PAIRING_CODE,
                'public_url' => 'https://site.example.com',
            ])
            ->assertRedirect('/cp/secretary')
            ->assertSessionHas('secretary_success');

        $settings = Setting::query()->findOrFail('relay')->value;
        $this->assertFalse($settings['enabled']);
        $this->assertSame('pending', $settings['billing_status']);
        $this->assertSame(4900, $settings['price']['amount']);
        $this->assertArrayNotHasKey('signing_secret', $settings);
        $this->assertArrayNotHasKey('route_token', $settings);

        $this->actingAs($owner)
            ->get('/cp/secretary')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('relay_setup.connected', false)
                ->where('relay_setup.payment_required', true)
                ->where('relay_setup.billing_status', 'pending')
                ->where('relay_setup.price.amount', 4900));
    }

    public function test_pairing_rejects_local_urls_and_unauthorized_users_before_network_io(): void
    {
        Http::fake();
        $owner = $this->owner();

        $this->from('/cp/secretary')
            ->actingAs($owner)
            ->post('/cp/secretary/setup/relay', [
                'pairing_code' => self::PAIRING_CODE,
                'public_url' => 'http://statamic-secretary.test',
            ])
            ->assertRedirect('/cp/secretary')
            ->assertSessionHasErrors('public_url');

        $role = Role::make('secretary-editor')->permissions([
            'access cp',
            'access secretary',
            'use secretary',
        ]);
        $role->save();
        $editor = User::make()->id('editor@example.com')->email('editor@example.com');
        $editor->assignRole($role)->save();

        $this->actingAs($editor)
            ->post('/cp/secretary/setup/relay', [
                'pairing_code' => self::PAIRING_CODE,
                'public_url' => 'https://site.example.com',
            ])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseMissing('secretary_settings', ['key' => 'relay']);
    }

    public function test_pairing_is_hidden_and_rejected_until_the_hosted_service_is_enabled(): void
    {
        config()->set('secretary.relay.pairing_enabled', false);
        Http::fake();
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post('/cp/secretary/setup/relay', [
                'pairing_code' => self::PAIRING_CODE,
                'public_url' => 'https://site.example.com',
            ])
            ->assertNotFound();

        $this->actingAs($owner)
            ->get('/cp/secretary')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('relay_setup.pairing_available', false)
                ->where('relay_setup.connected', false));

        Http::assertNothingSent();
    }

    public function test_credentials_saved_by_pairing_accept_a_real_signed_relay_delivery(): void
    {
        $this->fakePairingSuccess();
        $owner = $this->owner();
        Bus::fake();
        $this->actingAs($owner)->post('/cp/secretary/setup/relay', [
            'pairing_code' => self::PAIRING_CODE,
            'public_url' => 'https://site.example.com',
        ]);
        $payload = [
            'version' => 1,
            'provider_message_id' => 'paired-live-message',
            'sender' => 'owner@example.com',
            'subject' => 'Endre forsiden',
            'body' => 'Oppdater forsiden.',
            'sender_authenticated' => true,
            'spam_score' => -0.1,
            'route_token' => self::ROUTE_TOKEN,
            'conversation_token' => null,
            'rfc_message_id' => '<paired-live-message@example.com>',
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = app(RelaySignature::class)->headers(
            'POST',
            '/_secretary/webhooks/relay/inbound',
            $body,
        );

        $this->call(
            'POST',
            '/_secretary/webhooks/relay/inbound',
            server: [
                'CONTENT_TYPE' => 'application/json',
                ...collect($headers)->mapWithKeys(
                    fn (string $value, string $key): array => [
                        'HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value,
                    ],
                )->all(),
            ],
            content: $body,
        )
            ->assertOk()
            ->assertJson(['accepted' => true, 'duplicate' => false]);

        $this->assertDatabaseHas('secretary_messages', [
            'provider_message_id' => 'paired-live-message',
        ]);
        Bus::assertDispatchedAfterResponse(ProcessInboundEmail::class);
    }

    private function fakePairingSuccess(): void
    {
        Http::fake([
            'https://secretary.statamic.no/v1/pairings/claim' => Http::response(
                $this->pairingResponse(),
                201,
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function pairingResponse(): array
    {
        return [
            'accepted' => true,
            'status' => 'paired',
            'installation_id' => self::INSTALLATION_ID,
            'route_token' => self::ROUTE_TOKEN,
            'signing_secret' => $this->encodedSecret(),
            'address' => 'site.example.com@statamic.no',
            'route_address' => 'secretary+'.self::ROUTE_TOKEN.'@statamic.no',
        ];
    }

    private function encodedSecret(): string
    {
        return rtrim(strtr(base64_encode(self::SECRET), '+/', '-_'), '=');
    }

    private function owner()
    {
        $owner = User::make()->id('owner@example.com')->email('owner@example.com')->makeSuper();
        $owner->save();

        return $owner;
    }
}
