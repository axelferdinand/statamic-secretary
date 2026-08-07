<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Persistence;

use AxelFerdinand\StatamicSecretaryRelay\PublicSiteAlias;
use PDO;

final class SqliteSchema
{
    public static function migrate(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        foreach (self::statements() as $statement) {
            $pdo->exec($statement);
        }

        self::allowRepeatedInstallationPairings($pdo);
        self::addInstallationRotationColumns($pdo);
        self::addInstallationAliasColumn($pdo);
        self::addInstallationBillingColumns($pdo);
        self::backfillInstallationAliases($pdo);
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS relay_public_alias_unique
             ON relay_installations(public_alias)
             WHERE public_alias IS NOT NULL',
        );
        self::backfillInstallationRoutes($pdo);
    }

    /** @return array<int, string> */
    private static function statements(): array
    {
        return [
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS relay_installations (
                    id TEXT PRIMARY KEY,
                    route_token TEXT NOT NULL UNIQUE,
                    webhook_url TEXT NOT NULL,
                    signing_secret_ciphertext TEXT NOT NULL,
                    pending_signing_secret_ciphertext TEXT NULL,
                    previous_signing_secret_ciphertext TEXT NULL,
                    previous_secret_expires_at INTEGER NULL,
                    pending_rotation_id TEXT NULL,
                    last_rotation_id TEXT NULL,
                    rotation_started_at INTEGER NULL,
                    rotation_completed_at INTEGER NULL,
                    pending_route_token TEXT NULL,
                    pending_route_rotation_id TEXT NULL,
                    last_route_rotation_id TEXT NULL,
                    route_rotation_available_at INTEGER NULL,
                    public_alias TEXT NULL,
                    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
                    label TEXT NULL,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                )
                SQL,
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS relay_installation_senders (
                    installation_id TEXT NOT NULL,
                    sender TEXT NOT NULL,
                    created_at INTEGER NOT NULL,
                    PRIMARY KEY (installation_id, sender),
                    FOREIGN KEY (installation_id) REFERENCES relay_installations(id) ON DELETE CASCADE
                )
                SQL,
            'CREATE INDEX IF NOT EXISTS relay_sender_lookup ON relay_installation_senders(sender)',
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS relay_installation_routes (
                    route_token TEXT PRIMARY KEY,
                    installation_id TEXT NOT NULL,
                    status TEXT NOT NULL CHECK (status IN ('current', 'pending', 'retired')),
                    created_at INTEGER NOT NULL,
                    retired_at INTEGER NULL,
                    FOREIGN KEY (installation_id) REFERENCES relay_installations(id) ON DELETE CASCADE
                )
                SQL,
            'CREATE INDEX IF NOT EXISTS relay_route_installation ON relay_installation_routes(installation_id, status)',
            <<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS relay_current_route_per_installation
                ON relay_installation_routes(installation_id)
                WHERE status = 'current'
                SQL,
            <<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS relay_pending_route_per_installation
                ON relay_installation_routes(installation_id)
                WHERE status = 'pending'
                SQL,
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS relay_conversations (
                    token TEXT PRIMARY KEY,
                    installation_id TEXT NOT NULL,
                    route_token TEXT NOT NULL,
                    sender TEXT NOT NULL,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    FOREIGN KEY (installation_id) REFERENCES relay_installations(id) ON DELETE CASCADE
                )
                SQL,
            'CREATE INDEX IF NOT EXISTS relay_conversation_installation ON relay_conversations(installation_id)',
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS relay_inbound_claims (
                    provider_message_id TEXT PRIMARY KEY,
                    installation_id TEXT NOT NULL,
                    fingerprint TEXT NOT NULL,
                    status TEXT NOT NULL CHECK (status IN ('processing', 'complete')),
                    lease_owner TEXT NULL,
                    lease_expires_at INTEGER NULL,
                    sender TEXT NULL,
                    route_token TEXT NULL,
                    conversation_token TEXT NULL,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    FOREIGN KEY (installation_id) REFERENCES relay_installations(id) ON DELETE CASCADE
                )
                SQL,
            'CREATE INDEX IF NOT EXISTS relay_inbound_installation ON relay_inbound_claims(installation_id)',
            'CREATE INDEX IF NOT EXISTS relay_inbound_lease ON relay_inbound_claims(status, lease_expires_at)',
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS relay_nonces (
                    installation_id TEXT NOT NULL,
                    nonce TEXT NOT NULL,
                    expires_at INTEGER NOT NULL,
                    PRIMARY KEY (installation_id, nonce),
                    FOREIGN KEY (installation_id) REFERENCES relay_installations(id) ON DELETE CASCADE
                )
                SQL,
            'CREATE INDEX IF NOT EXISTS relay_nonce_expiry ON relay_nonces(expires_at)',
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS relay_reply_claims (
                    idempotency_key TEXT PRIMARY KEY,
                    installation_id TEXT NOT NULL,
                    fingerprint TEXT NOT NULL,
                    status TEXT NOT NULL CHECK (status IN ('processing', 'complete')),
                    lease_owner TEXT NULL,
                    lease_expires_at INTEGER NULL,
                    provider_message_id TEXT NULL,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    FOREIGN KEY (installation_id) REFERENCES relay_installations(id) ON DELETE CASCADE
                )
                SQL,
            'CREATE INDEX IF NOT EXISTS relay_reply_installation ON relay_reply_claims(installation_id)',
            'CREATE INDEX IF NOT EXISTS relay_reply_lease ON relay_reply_claims(status, lease_expires_at)',
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS relay_selection_claims (
                    provider_message_id TEXT PRIMARY KEY,
                    fingerprint TEXT NOT NULL,
                    status TEXT NOT NULL CHECK (status IN ('processing', 'complete')),
                    lease_owner TEXT NULL,
                    lease_expires_at INTEGER NULL,
                    provider_reply_id TEXT NULL,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                )
                SQL,
            'CREATE INDEX IF NOT EXISTS relay_selection_lease ON relay_selection_claims(status, lease_expires_at)',
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS relay_pairing_codes (
                    code_digest TEXT PRIMARY KEY,
                    status TEXT NOT NULL CHECK (status IN ('issued', 'complete')),
                    label TEXT NOT NULL,
                    senders_json TEXT NOT NULL,
                    expires_at INTEGER NOT NULL,
                    claim_fingerprint TEXT NULL,
                    installation_id TEXT NULL,
                    created_at INTEGER NOT NULL,
                    claimed_at INTEGER NULL,
                    FOREIGN KEY (installation_id) REFERENCES relay_installations(id) ON DELETE RESTRICT
                )
                SQL,
            'CREATE INDEX IF NOT EXISTS relay_pairing_expiry ON relay_pairing_codes(status, expires_at)',
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS relay_rate_limits (
                    scope TEXT NOT NULL,
                    subject_hash TEXT NOT NULL,
                    window_start INTEGER NOT NULL,
                    hits INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL,
                    PRIMARY KEY (scope, subject_hash)
                )
                SQL,
            'CREATE INDEX IF NOT EXISTS relay_rate_limit_expiry ON relay_rate_limits(expires_at)',
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS relay_postmark_poll_claims (
                    provider_message_id TEXT PRIMARY KEY,
                    status TEXT NOT NULL CHECK (status IN ('processing', 'complete')),
                    lease_owner TEXT NULL,
                    lease_expires_at INTEGER NULL,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                )
                SQL,
            'CREATE INDEX IF NOT EXISTS relay_postmark_poll_lease ON relay_postmark_poll_claims(status, lease_expires_at)',
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS relay_billing_events (
                    event_id TEXT PRIMARY KEY,
                    installation_id TEXT NOT NULL,
                    status TEXT NOT NULL,
                    created_at INTEGER NOT NULL,
                    FOREIGN KEY (installation_id) REFERENCES relay_installations(id) ON DELETE CASCADE
                )
                SQL,
            'CREATE INDEX IF NOT EXISTS relay_billing_event_installation ON relay_billing_events(installation_id, created_at)',
        ];
    }

    private static function addInstallationBillingColumns(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(relay_installations)')->fetchAll(PDO::FETCH_ASSOC);
        $existing = array_fill_keys(array_map(
            static fn (array $column): string => (string) $column['name'],
            $columns,
        ), true);
        $definitions = [
            'billing_status' => "TEXT NOT NULL DEFAULT 'beta'",
            'stripe_customer_id' => 'TEXT NULL',
            'stripe_subscription_id' => 'TEXT NULL',
            'billing_period_end' => 'INTEGER NULL',
            'checkout_id' => 'TEXT NULL',
            'checkout_url' => 'TEXT NULL',
            'checkout_expires_at' => 'INTEGER NULL',
        ];

        foreach ($definitions as $name => $definition) {
            if (! isset($existing[$name])) {
                $pdo->exec("ALTER TABLE relay_installations ADD COLUMN {$name} {$definition}");
            }
        }

        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS relay_stripe_customer_unique
             ON relay_installations(stripe_customer_id)
             WHERE stripe_customer_id IS NOT NULL',
        );
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS relay_stripe_subscription_unique
             ON relay_installations(stripe_subscription_id)
             WHERE stripe_subscription_id IS NOT NULL',
        );
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS relay_checkout_unique
             ON relay_installations(checkout_id)
             WHERE checkout_id IS NOT NULL',
        );
    }

    private static function addInstallationRotationColumns(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(relay_installations)')->fetchAll(PDO::FETCH_ASSOC);
        $existing = array_fill_keys(array_map(
            static fn (array $column): string => (string) $column['name'],
            $columns,
        ), true);
        $definitions = [
            'pending_signing_secret_ciphertext' => 'TEXT NULL',
            'previous_signing_secret_ciphertext' => 'TEXT NULL',
            'previous_secret_expires_at' => 'INTEGER NULL',
            'pending_rotation_id' => 'TEXT NULL',
            'last_rotation_id' => 'TEXT NULL',
            'rotation_started_at' => 'INTEGER NULL',
            'rotation_completed_at' => 'INTEGER NULL',
            'pending_route_token' => 'TEXT NULL',
            'pending_route_rotation_id' => 'TEXT NULL',
            'last_route_rotation_id' => 'TEXT NULL',
            'route_rotation_available_at' => 'INTEGER NULL',
        ];

        foreach ($definitions as $name => $definition) {
            if (! isset($existing[$name])) {
                $pdo->exec("ALTER TABLE relay_installations ADD COLUMN {$name} {$definition}");
            }
        }
    }

    private static function allowRepeatedInstallationPairings(PDO $pdo): void
    {
        $indexes = $pdo->query('PRAGMA index_list(relay_pairing_codes)')->fetchAll(PDO::FETCH_ASSOC);
        $hasLegacyInstallationConstraint = false;

        foreach ($indexes as $index) {
            if (($index['origin'] ?? null) !== 'u' || ! is_string($index['name'] ?? null)) {
                continue;
            }

            $columns = $pdo->query(
                'PRAGMA index_info('.$pdo->quote($index['name']).')',
            )->fetchAll(PDO::FETCH_ASSOC);

            if (count($columns) === 1 && ($columns[0]['name'] ?? null) === 'installation_id') {
                $hasLegacyInstallationConstraint = true;

                break;
            }
        }

        if (! $hasLegacyInstallationConstraint) {
            return;
        }

        $pdo->exec(
            <<<'SQL'
                CREATE TABLE relay_pairing_codes_without_unique_installation (
                    code_digest TEXT PRIMARY KEY,
                    status TEXT NOT NULL CHECK (status IN ('issued', 'complete')),
                    label TEXT NOT NULL,
                    senders_json TEXT NOT NULL,
                    expires_at INTEGER NOT NULL,
                    claim_fingerprint TEXT NULL,
                    installation_id TEXT NULL,
                    created_at INTEGER NOT NULL,
                    claimed_at INTEGER NULL,
                    FOREIGN KEY (installation_id) REFERENCES relay_installations(id) ON DELETE RESTRICT
                )
                SQL,
        );
        $pdo->exec(
            <<<'SQL'
                INSERT INTO relay_pairing_codes_without_unique_installation (
                    code_digest, status, label, senders_json, expires_at,
                    claim_fingerprint, installation_id, created_at, claimed_at
                )
                SELECT code_digest, status, label, senders_json, expires_at,
                       claim_fingerprint, installation_id, created_at, claimed_at
                FROM relay_pairing_codes
                SQL,
        );
        $pdo->exec('DROP TABLE relay_pairing_codes');
        $pdo->exec(
            'ALTER TABLE relay_pairing_codes_without_unique_installation RENAME TO relay_pairing_codes',
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS relay_pairing_expiry
             ON relay_pairing_codes(status, expires_at)',
        );
    }

    private static function addInstallationAliasColumn(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(relay_installations)')->fetchAll(PDO::FETCH_ASSOC);
        $existing = array_fill_keys(array_map(
            static fn (array $column): string => (string) $column['name'],
            $columns,
        ), true);

        if (! isset($existing['public_alias'])) {
            $pdo->exec('ALTER TABLE relay_installations ADD COLUMN public_alias TEXT NULL');
        }
    }

    private static function backfillInstallationAliases(PDO $pdo): void
    {
        $rows = $pdo->query(
            'SELECT id, route_token, webhook_url, public_alias
             FROM relay_installations
             ORDER BY created_at, id',
        )->fetchAll(PDO::FETCH_ASSOC);
        $used = [];

        foreach ($rows as $row) {
            $existing = is_string($row['public_alias'] ?? null)
                ? mb_strtolower(trim($row['public_alias']))
                : '';

            if (PublicSiteAlias::valid($existing) && ! isset($used[$existing])) {
                $used[$existing] = true;

                continue;
            }

            $base = PublicSiteAlias::fromWebhookUrl((string) $row['webhook_url']);
            $alias = isset($used[$base])
                ? PublicSiteAlias::withRouteSuffix($base, (string) $row['route_token'])
                : $base;

            if (isset($used[$alias])) {
                throw new \RuntimeException('A unique public email alias could not be backfilled.');
            }

            $statement = $pdo->prepare(
                'UPDATE relay_installations SET public_alias = :public_alias WHERE id = :id',
            );
            $statement->execute([
                'public_alias' => $alias,
                'id' => $row['id'],
            ]);
            $used[$alias] = true;
        }
    }

    private static function backfillInstallationRoutes(PDO $pdo): void
    {
        $pdo->exec(
            <<<'SQL'
                INSERT OR IGNORE INTO relay_installation_routes (
                    route_token, installation_id, status, created_at, retired_at
                )
                SELECT route_token, id, 'current', created_at, NULL
                FROM relay_installations
                SQL,
        );
    }
}
