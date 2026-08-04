<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Email\EmailConfiguration;
use AxelFerdinand\StatamicSecretary\Jobs\ProcessInboundEmail;
use AxelFerdinand\StatamicSecretary\Models\Setting;
use AxelFerdinand\StatamicSecretary\Postmark\PostmarkConnector;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class PostmarkSetupTest extends TestCase
{
    public function test_an_administrator_can_paste_the_postmark_token_in_the_control_panel(): void
    {
        config()->set('secretary.email.postmark.api_key');
        $this->fakePostmarkServer();
        $user = User::make()->id('owner@example.com')->email('owner@example.com')->makeSuper();
        $user->save();
        $token = 'postmark-server-token-from-onboarding';

        $this->actingAs($user)
            ->post('/cp/secretary/setup/postmark', [
                'api_key' => $token,
                'email' => 'secretary@example.com',
                'public_url' => 'https://secretary.example.com',
            ])
            ->assertRedirect('/cp/secretary')
            ->assertSessionHas('secretary_success');

        $settings = Setting::query()->findOrFail('email')->value;
        $this->assertSame($token, $settings['api_key']);
        $this->assertStringNotContainsString(
            $token,
            (string) DB::table('secretary_settings')->where('key', 'email')->value('value'),
        );
        $this->assertSame('control_panel', app(EmailConfiguration::class)->publicStatus()['token_source']);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Postmark-Server-Token', $token));
    }

    public function test_postmark_setup_explains_when_no_token_was_supplied(): void
    {
        config()->set('secretary.email.postmark.api_key');
        Http::fake();
        $user = User::make()->id('owner@example.com')->email('owner@example.com')->makeSuper();
        $user->save();

        $this->from('/cp/secretary')
            ->actingAs($user)
            ->post('/cp/secretary/setup/postmark', [
                'email' => 'secretary@example.com',
                'public_url' => 'https://secretary.example.com',
            ])
            ->assertRedirect('/cp/secretary')
            ->assertSessionHasErrors('postmark_api_key');

        Http::assertNothingSent();
    }

    public function test_a_super_user_can_connect_postmark_with_only_the_server_token(): void
    {
        config()->set('secretary.email.postmark.api_key', 'postmark-server-token');
        $this->fakePostmarkServer();
        $user = User::make()->id('owner@example.com')->email('owner@example.com')->makeSuper();
        $user->save();

        $this->actingAs($user)
            ->post('/cp/secretary/setup/postmark', [
                'email' => 'secretary@example.com',
                'public_url' => 'https://secretary.example.com',
            ])
            ->assertRedirect('/cp/secretary')
            ->assertSessionHas('secretary_success');

        $settings = Setting::query()->findOrFail('email')->value;

        $this->assertSame('secretary@example.com', $settings['from_address']);
        $this->assertSame('serverhash@inbound.postmarkapp.com', $settings['inbound_address']);
        $this->assertSame('Secretary Test', $settings['server_name']);
        $this->assertSame('https://secretary.example.com/_secretary/webhooks/postmark/inbound', $settings['webhook_endpoint']);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }

            $webhook = (string) data_get($request->data(), 'InboundHookUrl');
            $parts = parse_url($webhook);

            return $request->url() === 'https://api.postmarkapp.com/server'
                && $request->hasHeader('X-Postmark-Server-Token', 'postmark-server-token')
                && ($parts['scheme'] ?? null) === 'https'
                && ($parts['user'] ?? null) === 'secretary'
                && filled($parts['pass'] ?? null)
                && ($parts['host'] ?? null) === 'secretary.example.com'
                && ($parts['path'] ?? null) === '/_secretary/webhooks/postmark/inbound'
                && ! str_contains($webhook, 'postmark-server-token');
        });

        $status = app(EmailConfiguration::class)->publicStatus();
        $this->assertTrue($status['connected']);
        $this->assertTrue($status['enabled']);
        $this->assertStringNotContainsString('postmark-server-token', json_encode($status));
    }

    public function test_the_setup_page_exposes_a_short_forwarding_instruction_without_secrets(): void
    {
        config()->set('secretary.email.postmark.api_key', 'never-show-this-token');
        $this->fakePostmarkServer();
        $user = User::make()->id('owner@example.com')->email('owner@example.com')->makeSuper();
        $user->save();

        $this->actingAs($user)->post('/cp/secretary/setup/postmark', [
            'email' => 'secretary@example.com',
            'public_url' => 'https://secretary.example.com',
        ]);

        $this->actingAs($user)
            ->get('/cp/secretary')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('statamic-secretary::Secretary')
                ->where('email_enabled', true)
                ->where('email_setup.connected', true)
                ->where('email_setup.from_address', 'secretary@example.com')
                ->where('email_setup.inbound_address', 'serverhash@inbound.postmarkapp.com')
                ->where('email_setup.server_name', 'Secretary Test')
                ->missing('email_setup.api_key'));

        $this->assertStringNotContainsString(
            'never-show-this-token',
            json_encode(app(EmailConfiguration::class)->publicStatus()),
        );
    }

    public function test_local_and_insecure_webhook_addresses_are_rejected_before_postmark_is_called(): void
    {
        config()->set('secretary.email.postmark.api_key', 'postmark-server-token');
        Http::fake();
        $user = User::make()->id('owner@example.com')->email('owner@example.com')->makeSuper();
        $user->save();

        $this->from('/cp/secretary')
            ->actingAs($user)
            ->post('/cp/secretary/setup/postmark', [
                'email' => 'secretary@example.com',
                'public_url' => 'http://statamic-secretary.test',
            ])
            ->assertRedirect('/cp/secretary')
            ->assertSessionHasErrors('public_url');

        Http::assertNothingSent();
        $this->assertDatabaseMissing('secretary_settings', ['key' => 'email']);
    }

    public function test_a_secretary_user_without_configuration_permission_cannot_connect_postmark(): void
    {
        config()->set('secretary.email.postmark.api_key', 'postmark-server-token');
        Http::fake();
        $role = Role::make('secretary-editor')->permissions([
            'access cp',
            'access secretary',
            'use secretary',
        ]);
        $role->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com');
        $user->assignRole($role)->save();

        $this->actingAs($user)
            ->post('/cp/secretary/setup/postmark', [
                'email' => 'secretary@example.com',
                'public_url' => 'https://secretary.example.com',
            ])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseMissing('secretary_settings', ['key' => 'email']);
    }

    public function test_rotating_the_app_key_requires_postmark_to_be_reconnected(): void
    {
        config()->set('secretary.email.postmark.api_key', 'postmark-server-token');
        $this->fakePostmarkServer();
        $email = app(EmailConfiguration::class);

        app(PostmarkConnector::class)
            ->connect('secretary@example.com', 'https://secretary.example.com');

        $this->assertTrue($email->connected());

        config()->set('app.key', 'base64:a-different-application-key');

        $this->assertFalse($email->connected());
        $this->assertFalse($email->enabled());
    }

    public function test_a_connected_webhook_accepts_a_permitted_statamic_user_without_an_env_allowlist(): void
    {
        config()->set('secretary.email.postmark.api_key', 'postmark-server-token');
        $this->fakePostmarkServer();
        $owner = User::make()->id('owner@example.com')->email('owner@example.com')->makeSuper();
        $owner->save();
        $email = app(EmailConfiguration::class);

        app(PostmarkConnector::class)
            ->connect('secretary@example.com', 'https://secretary.example.com');

        Bus::fake();

        $this->withBasicAuth($email->webhookUsername(), $email->webhookPassword())
            ->postJson('/_secretary/webhooks/postmark/inbound', [
                'MessageID' => 'auto-setup-inbound-message',
                'TextBody' => 'Oppdater forsiden.',
                'FromFull' => ['Email' => 'owner@example.com'],
                'Headers' => [
                    ['Name' => 'X-Spam-Score', 'Value' => '-0.1'],
                    ['Name' => 'X-Spam-Tests', 'Value' => 'DKIM_SIGNED,DKIM_VALID,DKIM_VALID_AU'],
                ],
            ])
            ->assertOk()
            ->assertJson(['accepted' => true]);

        Bus::assertDispatched(ProcessInboundEmail::class);
    }

    private function fakePostmarkServer(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'ID' => 123,
                    'Name' => 'Secretary Test',
                    'DeliveryType' => 'Live',
                    'InboundAddress' => 'serverhash@inbound.postmarkapp.com',
                ]);
            }

            return Http::response([
                'ID' => 123,
                'Name' => 'Secretary Test',
                'DeliveryType' => 'Live',
                'InboundAddress' => 'serverhash@inbound.postmarkapp.com',
            ]);
        });
    }
}
