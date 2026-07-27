<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\InstallationManager;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteRelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteSchema;
use AxelFerdinand\StatamicSecretaryRelay\Security\Signature;
use PDO;
use PHPUnit\Framework\TestCase;

class HostedRelaySecretRotationTest extends TestCase
{
    public function test_rotation_is_retry_safe_encrypted_and_accepted_in_both_transition_directions(): void
    {
        $now = 1_800_000_000;
        [$pdo, $store] = $this->store($now);
        $original = $this->installation(str_repeat('o', 32));
        $store->saveInstallation($original);

        $prepared = $store->prepareSecretRotation($original->id);
        $retry = $store->prepareSecretRotation($original->id);

        $this->assertFalse($prepared->duplicate);
        $this->assertTrue($retry->duplicate);
        $this->assertSame($prepared->rotationId, $retry->rotationId);
        $this->assertSame($prepared->signingSecret, $retry->signingSecret);
        $this->assertMatchesRegularExpression('/^sr_[A-Za-z0-9_-]{43}$/D', $prepared->rotationId);

        $raw = implode("\n", array_map(
            static fn (mixed $value): string => (string) $value,
            $pdo->query(
                'SELECT signing_secret_ciphertext, pending_signing_secret_ciphertext
                 FROM relay_installations',
            )->fetch(PDO::FETCH_NUM),
        ));
        $encodedPending = rtrim(strtr(base64_encode($prepared->signingSecret), '+/', '-_'), '=');
        $this->assertStringNotContainsString($original->signingSecret, $raw);
        $this->assertStringNotContainsString($prepared->signingSecret, $raw);
        $this->assertStringNotContainsString($encodedPending, $raw);

        $pending = $store->installationById($original->id);
        $this->assertNotNull($pending);
        $this->assertSame($original->signingSecret, $pending->signingSecret);
        $this->assertSame($prepared->signingSecret, $pending->pendingSigningSecret);
        $this->assertSame($prepared->rotationId, $pending->pendingRotationId);

        $oldHeaders = Signature::headers(
            $original,
            'POST',
            '/v1/replies',
            '{}',
            $now,
            str_repeat('a', 32),
        );
        $newHeaders = Signature::headers(
            $this->installation($prepared->signingSecret),
            'POST',
            '/v1/replies',
            '{}',
            $now,
            str_repeat('b', 32),
        );
        $this->assertSame(
            $oldHeaders['Secretary-Signature'],
            Signature::headers(
                $pending,
                'POST',
                '/v1/replies',
                '{}',
                $now,
                str_repeat('a', 32),
            )['Secretary-Signature'],
        );
        Signature::verify($pending, $store, $oldHeaders, 'POST', '/v1/replies', '{}', $now);
        Signature::verify($pending, $store, $newHeaders, 'POST', '/v1/replies', '{}', $now);

        $admin = new InstallationManager($store);
        $admin->setActive($original->id, false);
        $this->assertSame(
            $prepared->rotationId,
            $store->installationById($original->id)?->pendingRotationId,
        );
        $admin->setActive($original->id, true);

        $promoted = $store->promoteSecretRotation(
            $original->id,
            $prepared->rotationId,
            300,
        );
        $promotedRetry = $store->promoteSecretRotation(
            $original->id,
            $prepared->rotationId,
            300,
        );
        $this->assertEquals($promoted, $promotedRetry);
        $this->assertSame($prepared->signingSecret, $promoted->signingSecret);
        $this->assertSame($original->signingSecret, $promoted->previousSigningSecret);
        $this->assertSame(1_800_000_300, $promoted->previousSecretExpiresAt);
        $this->assertNull($promoted->pendingSigningSecret);
        $this->assertNull($promoted->pendingRotationId);
        $this->assertSame($prepared->rotationId, $promoted->lastRotationId);
        $this->assertSame(
            $newHeaders['Secretary-Signature'],
            Signature::headers(
                $promoted,
                'POST',
                '/v1/replies',
                '{}',
                $now,
                str_repeat('b', 32),
            )['Secretary-Signature'],
        );

        Signature::verify(
            $promoted,
            $store,
            Signature::headers(
                $original,
                'POST',
                '/v1/replies',
                '{}',
                $now,
                str_repeat('c', 32),
            ),
            'POST',
            '/v1/replies',
            '{}',
            $now,
        );

        try {
            $store->prepareSecretRotation($original->id);
            $this->fail('A stacked rotation was prepared during the previous-secret grace period.');
        } catch (RelayRejected $exception) {
            $this->assertSame(
                'The previous signing-secret rotation is still in its grace period.',
                $exception->getMessage(),
            );
        }

        $now = 1_800_000_301;
        $afterGrace = $store->installationById($original->id);
        $this->assertNotNull($afterGrace);

        try {
            Signature::verify(
                $afterGrace,
                $store,
                Signature::headers(
                    $original,
                    'POST',
                    '/v1/replies',
                    '{}',
                    $now,
                    str_repeat('d', 32),
                ),
                'POST',
                '/v1/replies',
                '{}',
                $now,
            );
            $this->fail('The old signing secret was accepted after its grace period.');
        } catch (RelayRejected $exception) {
            $this->assertSame('Relay signature is invalid.', $exception->getMessage());
        }

        Signature::verify(
            $afterGrace,
            $store,
            Signature::headers(
                $this->installation($prepared->signingSecret),
                'POST',
                '/v1/replies',
                '{}',
                $now,
                str_repeat('e', 32),
            ),
            'POST',
            '/v1/replies',
            '{}',
            $now,
        );
        $next = $store->prepareSecretRotation($original->id);
        $this->assertNotSame($prepared->rotationId, $next->rotationId);
        $this->assertNotSame($prepared->signingSecret, $next->signingSecret);
    }

    public function test_promotion_rejects_the_wrong_rotation_and_invalid_grace_windows(): void
    {
        $now = 2000;
        [, $store] = $this->store($now);
        $installation = $this->installation(str_repeat('o', 32));
        $store->saveInstallation($installation);
        $rotation = $store->prepareSecretRotation($installation->id);

        foreach ([
            ['sr_'.str_repeat('z', 43), 300],
            [$rotation->rotationId, 299],
            [$rotation->rotationId, 3601],
        ] as [$rotationId, $graceSeconds]) {
            try {
                $store->promoteSecretRotation(
                    $installation->id,
                    $rotationId,
                    $graceSeconds,
                );
                $this->fail('An invalid signing-secret promotion was accepted.');
            } catch (RelayRejected) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(
            $rotation->rotationId,
            $store->installationById($installation->id)?->pendingRotationId,
        );
    }

    public function test_schema_migration_adds_rotation_columns_without_losing_existing_installations(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(
            <<<'SQL'
                CREATE TABLE relay_installations (
                    id TEXT PRIMARY KEY,
                    route_token TEXT NOT NULL UNIQUE,
                    webhook_url TEXT NOT NULL,
                    signing_secret_ciphertext TEXT NOT NULL,
                    active INTEGER NOT NULL DEFAULT 1,
                    label TEXT NULL,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                )
                SQL,
        );
        $pdo->exec(
            "INSERT INTO relay_installations VALUES (
                'si_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'raaaaaaaaaaaaaaaaaaaaaaaaa',
                'https://site.example.com/_secretary/webhooks/relay/inbound',
                'existing-ciphertext',
                1,
                'Existing site',
                1,
                1
            )",
        );

        SqliteSchema::migrate($pdo);
        SqliteSchema::migrate($pdo);

        $columns = $pdo->query('PRAGMA table_info(relay_installations)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($columns, 'name');
        $this->assertContains('pending_signing_secret_ciphertext', $names);
        $this->assertContains('previous_signing_secret_ciphertext', $names);
        $this->assertContains('previous_secret_expires_at', $names);
        $this->assertContains('pending_rotation_id', $names);
        $this->assertContains('last_rotation_id', $names);
        $this->assertContains('rotation_started_at', $names);
        $this->assertContains('rotation_completed_at', $names);
        $this->assertContains('pending_route_token', $names);
        $this->assertContains('pending_route_rotation_id', $names);
        $this->assertContains('last_route_rotation_id', $names);
        $this->assertContains('route_rotation_available_at', $names);
        $this->assertSame(
            'current',
            $pdo->query(
                "SELECT status FROM relay_installation_routes
                 WHERE route_token = 'raaaaaaaaaaaaaaaaaaaaaaaaa'",
            )->fetchColumn(),
        );
        $this->assertSame(
            'Existing site',
            $pdo->query(
                "SELECT label FROM relay_installations
                 WHERE id = 'si_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'",
            )->fetchColumn(),
        );
    }

    /** @return array{PDO, SqliteRelayStore} */
    private function store(int &$now): array
    {
        $pdo = new PDO('sqlite::memory:');
        SqliteSchema::migrate($pdo);

        return [
            $pdo,
            new SqliteRelayStore(
                $pdo,
                random_bytes(32),
                str_repeat('w', 22),
                30,
                static function () use (&$now): int {
                    return $now;
                },
            ),
        ];
    }

    private function installation(string $secret): Installation
    {
        return new Installation(
            'si_'.str_repeat('a', 32),
            'r'.str_repeat('a', 25),
            'https://site.example.com/_secretary/webhooks/relay/inbound',
            $secret,
            ['editor@example.com'],
            true,
            'Site',
        );
    }
}
