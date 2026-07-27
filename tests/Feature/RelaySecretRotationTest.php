<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Exceptions\RelaySecretRotationFailed;
use AxelFerdinand\StatamicSecretary\Models\Setting;
use AxelFerdinand\StatamicSecretary\Relay\RelayConfiguration;
use AxelFerdinand\StatamicSecretary\Relay\RelaySecretRotation;
use AxelFerdinand\StatamicSecretary\Relay\RelaySignature;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteRelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteSchema;
use AxelFerdinand\StatamicSecretaryRelay\Security\Signature as HostedRelaySignature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RelaySecretRotationTest extends TestCase
{
    private const INSTALLATION_ID = 'si_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const ROUTE_TOKEN = 'raaaaaaaaaaaaaaaaaaaaaaaaa';

    private const ROTATION_ID = 'sr_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OLD_SECRET = 'oooooooooooooooooooooooooooooooo';

    private const NEW_SECRET = 'nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn';

    public function test_site_installation_is_encrypted_idempotent_and_cross_package_compatible(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
        $this->connect(self::OLD_SECRET);
        $rotation = app(RelaySecretRotation::class);

        $installed = $rotation->install(
            $this->encode(self::NEW_SECRET),
            self::ROTATION_ID,
            15,
        );
        $retry = $rotation->install(
            $this->encode(self::NEW_SECRET),
            self::ROTATION_ID,
            15,
        );

        $this->assertFalse($installed['duplicate']);
        $this->assertTrue($retry['duplicate']);
        $this->assertSame(1_800_000_900, $installed['grace_expires_at']);
        $this->assertSame($installed['grace_expires_at'], $retry['grace_expires_at']);

        $settings = Setting::query()->findOrFail('relay')->value;
        $this->assertSame($this->encode(self::NEW_SECRET), $settings['signing_secret']);
        $this->assertSame($this->encode(self::OLD_SECRET), $settings['previous_signing_secret']);
        $this->assertSame(self::ROTATION_ID, $settings['last_rotation_id']);
        $this->assertSame(1_800_000_900, $settings['previous_secret_expires_at']);

        $raw = (string) DB::table('secretary_settings')->where('key', 'relay')->value('value');

        foreach ([
            self::OLD_SECRET,
            self::NEW_SECRET,
            $this->encode(self::OLD_SECRET),
            $this->encode(self::NEW_SECRET),
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }

        $configuration = app(RelayConfiguration::class);
        $this->assertSame(self::NEW_SECRET, $configuration->secret());
        $this->assertSame(self::OLD_SECRET, $configuration->previousSecret());
        $this->assertSame(
            [self::NEW_SECRET, self::OLD_SECRET],
            $configuration->verificationSecrets(1_800_000_000),
        );
        $this->assertSame(
            [self::NEW_SECRET],
            $configuration->verificationSecrets(1_800_000_901),
        );

        $body = '{"version":1}';
        $oldInbound = HostedRelaySignature::headers(
            $this->installation(self::OLD_SECRET),
            'POST',
            '/_secretary/webhooks/relay/inbound',
            $body,
            1_800_000_000,
            str_repeat('a', 32),
        );
        $newInbound = HostedRelaySignature::headers(
            $this->installation(self::NEW_SECRET),
            'POST',
            '/_secretary/webhooks/relay/inbound',
            $body,
            1_800_000_000,
            str_repeat('b', 32),
        );
        app(RelaySignature::class)->verify($this->request($body, $oldInbound));
        app(RelaySignature::class)->verify($this->request($body, $newInbound));

        $pdo = new PDO('sqlite::memory:');
        SqliteSchema::migrate($pdo);
        $relayStore = new SqliteRelayStore(
            $pdo,
            random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES),
            str_repeat('w', 22),
            30,
            static fn (): int => 1_800_000_000,
        );
        $pendingRelay = new Installation(
            self::INSTALLATION_ID,
            self::ROUTE_TOKEN,
            'https://site.example.com/_secretary/webhooks/relay/inbound',
            self::OLD_SECRET,
            ['editor@example.com'],
            true,
            'Site',
            self::NEW_SECRET,
            null,
            null,
            self::ROTATION_ID,
        );
        $relayStore->saveInstallation($pendingRelay);
        $siteHeaders = app(RelaySignature::class)->headers(
            'POST',
            '/v1/replies',
            $body,
            1_800_000_000,
            str_repeat('c', 32),
        );
        HostedRelaySignature::verify(
            $relayStore->installationById(self::INSTALLATION_ID),
            $relayStore,
            $siteHeaders,
            'POST',
            '/v1/replies',
            $body,
            1_800_000_000,
        );

        Carbon::setTestNow();
    }

    public function test_old_inbound_secret_is_rejected_after_grace_and_stacked_or_conflicting_rotations_fail(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_810_000_000));
        $this->connect(self::OLD_SECRET);
        $rotation = app(RelaySecretRotation::class);
        $rotation->install($this->encode(self::NEW_SECRET), self::ROTATION_ID, 5);

        foreach ([
            [$this->encode(str_repeat('x', 32)), self::ROTATION_ID],
            [$this->encode(str_repeat('y', 32)), 'sr_'.str_repeat('b', 43)],
        ] as [$secret, $rotationId]) {
            try {
                $rotation->install($secret, $rotationId, 5);
                $this->fail('A conflicting or stacked relay rotation was accepted.');
            } catch (RelaySecretRotationFailed) {
                $this->addToAssertionCount(1);
            }
        }

        Carbon::setTestNow(Carbon::createFromTimestamp(1_810_000_301));
        $headers = HostedRelaySignature::headers(
            $this->installation(self::OLD_SECRET),
            'POST',
            '/_secretary/webhooks/relay/inbound',
            '{}',
            1_810_000_301,
            str_repeat('d', 32),
        );

        try {
            app(RelaySignature::class)->verify($this->request('{}', $headers));
            $this->fail('The old relay signing secret was accepted after the grace period.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_environment_secret_override_blocks_database_rotation(): void
    {
        $this->connect(self::OLD_SECRET);
        config()->set('secretary.relay.signing_secret', $this->encode(self::OLD_SECRET));

        try {
            app(RelaySecretRotation::class)->install(
                $this->encode(self::NEW_SECRET),
                self::ROTATION_ID,
            );
            $this->fail('A database rotation overrode an environment signing secret.');
        } catch (RelaySecretRotationFailed $exception) {
            $this->assertStringContainsString('SECRETARY_RELAY_SIGNING_SECRET', $exception->getMessage());
        }
    }

    public function test_artisan_command_reads_the_secret_interactively_without_printing_it(): void
    {
        $this->connect(self::OLD_SECRET);
        $encoded = $this->encode(self::NEW_SECRET);

        $this->artisan('secretary:relay-install-secret-rotation', [
            'rotation_id' => self::ROTATION_ID,
            '--grace-minutes' => 15,
        ])
            ->expectsQuestion('Paste the new relay signing secret', $encoded)
            ->expectsOutputToContain('Relay signing-secret rotation installed.')
            ->doesntExpectOutputToContain(self::NEW_SECRET)
            ->doesntExpectOutputToContain($encoded)
            ->assertSuccessful();

        $this->assertSame(
            $encoded,
            Setting::query()->findOrFail('relay')->value['signing_secret'],
        );
    }

    private function connect(string $secret): void
    {
        config()->set('secretary.relay.enabled', null);
        config()->set('secretary.relay.installation_id', null);
        config()->set('secretary.relay.route_token', null);
        config()->set('secretary.relay.signing_secret', null);
        app(RelayConfiguration::class)->store([
            'enabled' => true,
            'installation_id' => self::INSTALLATION_ID,
            'route_token' => self::ROUTE_TOKEN,
            'signing_secret' => $this->encode($secret),
            'address' => 'secretary+'.self::ROUTE_TOKEN.'@statamic.no',
            'base_url' => 'https://secretary.statamic.no',
            'connected_at' => now()->toIso8601String(),
        ]);
    }

    private function installation(string $secret): Installation
    {
        return new Installation(
            self::INSTALLATION_ID,
            self::ROUTE_TOKEN,
            'https://site.example.com/_secretary/webhooks/relay/inbound',
            $secret,
            ['editor@example.com'],
            true,
            'Site',
        );
    }

    /** @param  array<string, string>  $headers */
    private function request(string $body, array $headers): Request
    {
        try {
            $request = Request::create(
                '/_secretary/webhooks/relay/inbound',
                'POST',
                content: $body,
            );
        } catch (BadRequestException) {
            $this->fail('Could not construct a signed relay test request.');
        }

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    private function encode(string $secret): string
    {
        return rtrim(strtr(base64_encode($secret), '+/', '-_'), '=');
    }
}
