<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Exceptions\RelayRouteRotationFailed;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Models\Setting;
use AxelFerdinand\StatamicSecretary\Relay\RelayConfiguration;
use AxelFerdinand\StatamicSecretary\Relay\RelayRouteRotation;
use AxelFerdinand\StatamicSecretary\Relay\RelaySignature;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Statamic\Facades\User;

class RelayRouteRotationTest extends TestCase
{
    private const INSTALLATION_ID = 'si_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OLD_ROUTE = 'raaaaaaaaaaaaaaaaaaaaaaaaa';

    private const NEW_ROUTE = 'rbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const ROTATION_ID = 'rr_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SECRET = 'ssssssssssssssssssssssssssssssss';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('secretary.relay.enabled', null);
        config()->set('secretary.relay.installation_id', null);
        config()->set('secretary.relay.route_token', null);
        config()->set('secretary.relay.signing_secret', null);
        config()->set('secretary.relay.max_clock_skew', 300);
        config()->set('secretary.relay.cache_store', 'array');
        Bus::fake();
        User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper()->save();
    }

    public function test_rotation_preserves_old_threads_but_retires_the_old_new_thread_alias(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
        $this->connect();
        $first = $this->postSigned($this->payload('old-thread'))->assertOk();
        $oldConversationToken = $first->json('conversation_token');
        $this->assertIsString($oldConversationToken);

        $rotation = app(RelayRouteRotation::class);
        $installed = $rotation->install(self::NEW_ROUTE, self::ROTATION_ID, 5);
        $retry = $rotation->install(self::NEW_ROUTE, self::ROTATION_ID, 5);
        $this->assertFalse($installed['duplicate']);
        $this->assertTrue($retry['duplicate']);
        $this->assertSame(1_800_000_300, $installed['transition_expires_at']);
        $this->assertSame(
            'secretary+'.self::NEW_ROUTE.'@statamic.no',
            $installed['address'],
        );

        $configuration = app(RelayConfiguration::class);
        $this->assertSame(self::NEW_ROUTE, $configuration->routeToken());
        $this->assertSame([self::OLD_ROUTE], $configuration->retiredRouteTokens());
        $this->assertTrue($configuration->acceptsRouteToken(self::OLD_ROUTE, null));
        $this->assertTrue($configuration->acceptsRouteToken(self::NEW_ROUTE, null));

        $this->postSigned($this->payload(
            'old-transition-thread',
            routeToken: self::OLD_ROUTE,
        ))->assertOk();
        $this->postSigned($this->payload(
            'new-route-thread',
            routeToken: self::NEW_ROUTE,
        ))->assertOk();

        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_301));
        $this->assertFalse($configuration->acceptsRouteToken(self::OLD_ROUTE, null));
        $this->assertTrue($configuration->acceptsRouteToken(
            self::OLD_ROUTE,
            $oldConversationToken,
        ));
        $this->postSigned($this->payload(
            'retired-new-thread',
            routeToken: self::OLD_ROUTE,
        ))->assertForbidden();
        $this->postSigned($this->payload(
            'retired-follow-up',
            [
                'conversation_token' => $oldConversationToken,
                'body' => 'Følg opp den gamle tråden.',
            ],
            self::OLD_ROUTE,
        ))->assertOk();

        $oldConversation = Conversation::query()
            ->where('context->relay_conversation_token', $oldConversationToken)
            ->firstOrFail();
        $this->assertSame(
            self::OLD_ROUTE,
            data_get($oldConversation->context, 'relay_route_token'),
        );
        $this->assertSame(
            self::NEW_ROUTE,
            Setting::query()->findOrFail('relay')->value['route_token'],
        );

        Carbon::setTestNow();
    }

    public function test_conflicts_stacking_and_environment_override_are_rejected(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_810_000_000));
        $this->connect();
        $rotation = app(RelayRouteRotation::class);
        $rotation->install(self::NEW_ROUTE, self::ROTATION_ID, 5);

        foreach ([
            [self::OLD_ROUTE, self::ROTATION_ID],
            ['r'.str_repeat('c', 25), 'rr_'.str_repeat('b', 43)],
        ] as [$routeToken, $rotationId]) {
            try {
                $rotation->install($routeToken, $rotationId, 5);
                $this->fail('A conflicting or stacked route rotation was accepted.');
            } catch (RelayRouteRotationFailed) {
                $this->addToAssertionCount(1);
            }
        }

        config()->set('secretary.relay.route_token', self::NEW_ROUTE);

        try {
            $rotation->install('r'.str_repeat('d', 25), 'rr_'.str_repeat('d', 43));
            $this->fail('A database rotation overrode an environment route.');
        } catch (RelayRouteRotationFailed $exception) {
            $this->assertStringContainsString(
                'SECRETARY_RELAY_ROUTE_TOKEN',
                $exception->getMessage(),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_artisan_command_installs_a_prepared_route_without_exposing_retired_routes(): void
    {
        $this->connect();

        $this->artisan('secretary:relay-install-route-rotation', [
            'rotation_id' => self::ROTATION_ID,
            'route_token' => self::NEW_ROUTE,
            '--transition-minutes' => 15,
        ])
            ->expectsOutputToContain('Relay route rotation installed.')
            ->assertSuccessful();

        $settings = Setting::query()->findOrFail('relay')->value;
        $this->assertSame(self::NEW_ROUTE, $settings['route_token']);
        $this->assertSame([self::OLD_ROUTE], $settings['retired_route_tokens']);
        $this->assertArrayNotHasKey(
            'retired_route_tokens',
            app(RelayConfiguration::class)->publicStatus(),
        );
    }

    private function connect(): void
    {
        app(RelayConfiguration::class)->store([
            'enabled' => true,
            'installation_id' => self::INSTALLATION_ID,
            'route_token' => self::OLD_ROUTE,
            'signing_secret' => rtrim(strtr(base64_encode(self::SECRET), '+/', '-_'), '='),
            'address' => 'secretary+'.self::OLD_ROUTE.'@statamic.no',
            'base_url' => 'https://secretary.statamic.no',
            'connected_at' => now()->toIso8601String(),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(
        string $providerMessageId,
        array $overrides = [],
        string $routeToken = self::OLD_ROUTE,
    ): array {
        return [
            'version' => 1,
            'provider_message_id' => $providerMessageId,
            'sender' => 'editor@example.com',
            'subject' => 'Oppdater siden',
            'body' => 'Lag et utkast.',
            'sender_authenticated' => true,
            'spam_score' => -0.1,
            'route_token' => $routeToken,
            'conversation_token' => null,
            'rfc_message_id' => '<'.$providerMessageId.'@example.com>',
            ...$overrides,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function postSigned(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = app(RelaySignature::class)->headers(
            'POST',
            '/_secretary/webhooks/relay/inbound',
            $body,
        );

        return $this->call(
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
        );
    }
}
