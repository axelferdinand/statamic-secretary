<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteRelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteSchema;
use PDO;
use PHPUnit\Framework\TestCase;

class HostedRelayRateLimitTest extends TestCase
{
    private ?string $databasePath = null;

    protected function tearDown(): void
    {
        if ($this->databasePath === null) {
            return;
        }

        foreach ([
            $this->databasePath,
            $this->databasePath.'-shm',
            $this->databasePath.'-wal',
        ] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_limits_are_atomic_across_workers_hashed_at_rest_and_reset_by_window(): void
    {
        $now = 1_800_000_000;
        [$pdoA, $storeA, $storeB, $key] = $this->stores($now);
        $subject = '203.0.113.42';

        $first = $storeA->consumeRateLimit('pairing_source', $subject, 3, 60);
        $second = $storeB->consumeRateLimit('pairing_source', $subject, 3, 60);
        $third = $storeA->consumeRateLimit('pairing_source', $subject, 3, 60);
        $blocked = $storeB->consumeRateLimit('pairing_source', $subject, 3, 60);

        $this->assertTrue($first->allowed);
        $this->assertSame(2, $first->remaining);
        $this->assertTrue($second->allowed);
        $this->assertTrue($third->allowed);
        $this->assertFalse($blocked->allowed);
        $this->assertSame(0, $blocked->remaining);
        $this->assertSame(60, $blocked->retryAfter($now));
        $this->assertSame(
            hash_hmac('sha256', $subject, $key),
            $pdoA->query(
                "SELECT subject_hash FROM relay_rate_limits
                 WHERE scope = 'pairing_source'",
            )->fetchColumn(),
        );
        $databaseBytes = file_get_contents($this->databasePath);
        $this->assertIsString($databaseBytes);
        $this->assertStringNotContainsString($subject, $databaseBytes);

        $separateScope = $storeB->consumeRateLimit(
            'reply_source',
            $subject,
            1,
            60,
        );
        $this->assertTrue($separateScope->allowed);

        $now += 60;
        $reset = $storeA->consumeRateLimit(
            'pairing_source',
            $subject,
            3,
            60,
        );
        $this->assertTrue($reset->allowed);
        $this->assertSame(2, $reset->remaining);
    }

    public function test_invalid_limits_are_rejected_and_expired_buckets_are_pruned(): void
    {
        $now = 1_810_000_000;
        [$pdo, $store] = $this->stores($now);

        foreach ([
            ['', 'subject', 1, 60],
            ['valid_scope', '', 1, 60],
            ['valid_scope', 'subject', 0, 60],
            ['valid_scope', 'subject', 1, 9],
        ] as [$scope, $subject, $limit, $window]) {
            try {
                $store->consumeRateLimit($scope, $subject, $limit, $window);
                $this->fail('An invalid rate-limit request was accepted.');
            } catch (RelayRejected) {
                $this->addToAssertionCount(1);
            }
        }

        $store->consumeRateLimit('pairing_source', 'subject', 1, 60);
        $this->assertSame(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM relay_rate_limits')->fetchColumn(),
        );
        $store->prune($now, $now + 61);
        $this->assertSame(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM relay_rate_limits')->fetchColumn(),
        );
    }

    /** @return array{PDO, SqliteRelayStore, SqliteRelayStore, string} */
    private function stores(int &$now): array
    {
        $path = tempnam(sys_get_temp_dir(), 'statamic-secretary-rate-limit-');

        if (! is_string($path)) {
            $this->fail('Could not create a relay rate-limit database.');
        }

        $this->databasePath = $path;
        $pdoA = new PDO('sqlite:'.$path);
        SqliteSchema::migrate($pdoA);
        $key = random_bytes(32);
        $clock = static function () use (&$now): int {
            return $now;
        };

        return [
            $pdoA,
            new SqliteRelayStore(
                $pdoA,
                $key,
                str_repeat('a', 22),
                30,
                $clock,
            ),
            new SqliteRelayStore(
                new PDO('sqlite:'.$path),
                $key,
                str_repeat('b', 22),
                30,
                $clock,
            ),
            $key,
        ];
    }
}
