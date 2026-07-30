<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretaryRelay\Data\ClaimState;
use AxelFerdinand\StatamicSecretaryRelay\Data\ConversationRoute;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundDelivery;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteRelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteSchema;
use PDO;
use PHPUnit\Framework\TestCase;

class HostedRelayPersistenceTest extends TestCase
{
    /** @var array<int, string> */
    private array $databasePaths = [];

    protected function tearDown(): void
    {
        foreach ($this->databasePaths as $path) {
            foreach ([$path, $path.'-shm', $path.'-wal'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_installations_are_durable_with_encrypted_secrets_and_exact_sender_memberships(): void
    {
        [$pdo, $store] = $this->database();
        $installation = $this->installation(
            senders: ['editor@example.com', 'owner@example.com'],
            secret: str_repeat('s', 32),
        );

        $store->saveInstallation($installation);

        $ciphertext = $pdo->query('SELECT signing_secret_ciphertext FROM relay_installations')->fetchColumn();
        $this->assertIsString($ciphertext);
        $this->assertStringStartsWith('o1:', $ciphertext);
        $this->assertNotSame($installation->signingSecret, $ciphertext);
        $this->assertStringNotContainsString($installation->signingSecret, $ciphertext);

        $loaded = $store->installationByRouteToken($installation->routeToken);
        $this->assertEquals($installation, $loaded);
        $this->assertSame(
            [$installation->id],
            array_map(
                static fn (Installation $candidate): string => $candidate->id,
                $store->installationsForSender('EDITOR@example.com'),
            ),
        );
        $this->assertSame([], $store->installationsForSender('unknown@example.com'));
        $this->assertSame(
            $installation->signingSecret,
            SqliteRelayStore::encryptionKeyFromBase64(base64_encode($installation->signingSecret)),
        );
    }

    public function test_installation_rejects_invalid_or_duplicate_sender_memberships(): void
    {
        foreach ([
            ['not-an-email'],
            ['editor@example.com', 'EDITOR@example.com'],
        ] as $senders) {
            try {
                $this->installation(senders: $senders);
                $this->fail('An invalid sender membership was accepted.');
            } catch (RelayRejected $exception) {
                $this->assertSame('Installation configuration is invalid.', $exception->getMessage());
            }
        }
    }

    public function test_inbound_claims_are_atomic_fingerprint_bound_and_recover_after_a_crashed_lease(): void
    {
        $now = 1000;
        [$pdoA, $storeA, $path, $key] = $this->database($now, str_repeat('a', 22));
        $storeA->saveInstallation($this->installation());
        $storeB = $this->store($this->connection($path), $key, $now, str_repeat('b', 22));
        $fingerprint = hash('sha256', 'request-a');

        $this->assertSame(
            ClaimState::New,
            $storeA->claimInbound('provider-a', $this->installation()->id, $fingerprint),
        );
        $this->assertSame(
            ClaimState::Processing,
            $storeB->claimInbound('provider-a', $this->installation()->id, $fingerprint),
        );
        $this->assertSame(
            ClaimState::Conflict,
            $storeB->claimInbound('provider-a', $this->installation()->id, hash('sha256', 'changed')),
        );

        $now = 1031;
        $storeB = $this->store($this->connection($path), $key, $now, str_repeat('b', 22));
        $this->assertSame(
            ClaimState::New,
            $storeB->claimInbound('provider-a', $this->installation()->id, $fingerprint),
        );

        $storeA->releaseInbound('provider-a', $this->installation()->id);
        $storeB->completeInbound(new InboundDelivery(
            'provider-a',
            $this->installation()->id,
            'editor@example.com',
            $this->installation()->routeToken,
            'c'.str_repeat('c', 25),
        ));

        $delivery = $storeA->inboundDelivery('provider-a');
        $this->assertNotNull($delivery);
        $this->assertSame('c'.str_repeat('c', 25), $delivery->conversationToken);
        $this->assertSame(
            ClaimState::Complete,
            $storeA->claimInbound('provider-a', $this->installation()->id, $fingerprint),
        );
        $this->assertSame(1, (int) $pdoA->query('SELECT COUNT(*) FROM relay_inbound_claims')->fetchColumn());
    }

    public function test_postmark_poll_claims_are_atomic_and_recover_after_a_crashed_lease(): void
    {
        $now = 1500;
        [$pdoA, $storeA, $path, $key] = $this->database($now, str_repeat('a', 22));
        $storeB = $this->store($this->connection($path), $key, $now, str_repeat('b', 22));

        $this->assertSame(ClaimState::New, $storeA->claimPostmarkPoll('postmark-message-a'));
        $this->assertSame(ClaimState::Processing, $storeB->claimPostmarkPoll('postmark-message-a'));

        $now = 1531;
        $this->assertSame(ClaimState::New, $storeB->claimPostmarkPoll('postmark-message-a'));

        try {
            $storeA->completePostmarkPoll('postmark-message-a');
            $this->fail('An expired Postmark poll lease was completed.');
        } catch (RelayRejected $exception) {
            $this->assertSame(
                'Postmark poll claim lease is no longer owned by this worker.',
                $exception->getMessage(),
            );
        }

        $storeA->releasePostmarkPoll('postmark-message-a');
        $storeB->completePostmarkPoll('postmark-message-a');
        $this->assertSame(ClaimState::Complete, $storeA->claimPostmarkPoll('postmark-message-a'));
        $this->assertSame(
            1,
            (int) $pdoA->query('SELECT COUNT(*) FROM relay_postmark_poll_claims')->fetchColumn(),
        );
    }

    public function test_reply_claims_are_atomic_and_an_expired_owner_cannot_complete_or_release_them(): void
    {
        $now = 2000;
        [, $storeA, $path, $key] = $this->database($now, str_repeat('a', 22));
        $storeA->saveInstallation($this->installation());
        $storeB = $this->store($this->connection($path), $key, $now, str_repeat('b', 22));
        $fingerprint = hash('sha256', 'reply-a');
        $keyName = 'secretary-reply-'.str_repeat('a', 24);

        $this->assertSame(ClaimState::New, $storeA->claimReply($keyName, $this->installation()->id, $fingerprint));
        $this->assertSame(ClaimState::Processing, $storeB->claimReply($keyName, $this->installation()->id, $fingerprint));
        $this->assertSame(
            ClaimState::Conflict,
            $storeB->claimReply($keyName, $this->installation()->id, hash('sha256', 'changed')),
        );

        $now = 2031;
        $storeB = $this->store($this->connection($path), $key, $now, str_repeat('b', 22));
        $this->assertSame(ClaimState::New, $storeB->claimReply($keyName, $this->installation()->id, $fingerprint));

        try {
            $storeA->completeReply($keyName, $this->installation()->id, 'wrong-owner');
            $this->fail('An expired claim owner completed a reclaimed reply.');
        } catch (RelayRejected $exception) {
            $this->assertSame('Reply claim lease is no longer owned by this worker.', $exception->getMessage());
        }

        $storeA->releaseReply($keyName, $this->installation()->id);
        $storeB->completeReply($keyName, $this->installation()->id, 'postmark-reply-a');
        $this->assertSame('postmark-reply-a', $storeA->completedReplyProviderId($keyName, $this->installation()->id));
        $this->assertSame(ClaimState::Complete, $storeA->claimReply($keyName, $this->installation()->id, $fingerprint));
    }

    public function test_nonces_are_single_use_across_workers_and_reusable_only_after_expiry(): void
    {
        $now = 3000;
        [, $storeA, $path, $key] = $this->database($now, str_repeat('a', 22));
        $storeA->saveInstallation($this->installation());
        $storeB = $this->store($this->connection($path), $key, $now, str_repeat('b', 22));

        $this->assertTrue($storeA->consumeNonce($this->installation()->id, str_repeat('n', 32), 3300));
        $this->assertFalse($storeB->consumeNonce($this->installation()->id, str_repeat('n', 32), 3300));

        $now = 3301;
        $storeB = $this->store($this->connection($path), $key, $now, str_repeat('b', 22));
        $this->assertTrue($storeB->consumeNonce($this->installation()->id, str_repeat('n', 32), 3600));
    }

    public function test_conversation_tokens_cannot_be_rebound_and_retention_prunes_only_delivery_metadata(): void
    {
        $now = 4000;
        [$pdo, $store] = $this->database($now);
        $store->saveInstallation($this->installation());
        $conversation = new ConversationRoute(
            'c'.str_repeat('c', 25),
            $this->installation()->id,
            $this->installation()->routeToken,
            'editor@example.com',
        );
        $store->saveConversation($conversation);
        $store->saveConversation($conversation);

        try {
            $store->saveConversation(new ConversationRoute(
                $conversation->token,
                $conversation->installationId,
                $conversation->routeToken,
                'other@example.com',
            ));
            $this->fail('A conversation token was rebound to another sender.');
        } catch (RelayRejected $exception) {
            $this->assertSame('Conversation token collision.', $exception->getMessage());
        }

        $fingerprint = hash('sha256', 'retained-request');
        $store->claimInbound('retained-inbound', $this->installation()->id, $fingerprint);
        $store->completeInbound(new InboundDelivery(
            'retained-inbound',
            $this->installation()->id,
            'editor@example.com',
            $this->installation()->routeToken,
            $conversation->token,
        ));
        $store->claimReply('secretary-reply-'.str_repeat('p', 24), $this->installation()->id, hash('sha256', 'stale-reply'));
        $store->claimSelection('selection-inbound', hash('sha256', 'selection'));
        $store->completeSelection('selection-inbound', 'selection-reply');
        $store->consumeNonce($this->installation()->id, str_repeat('z', 32), 4100);

        $counts = $store->prune(4500, 5000);

        $this->assertSame([
            'nonces' => 1,
            'inbound' => 1,
            'replies' => 1,
            'selections' => 1,
            'pairings' => 0,
            'postmark_poll' => 0,
        ], $counts);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM relay_conversations')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM relay_installations')->fetchColumn());
    }

    /**
     * @return array{PDO, SqliteRelayStore, string, string}
     */
    private function database(int &$now = 1000, string $workerId = 'worker_aaaaaaaaaaaaaaaaaaaaaa'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'statamic-secretary-relay-');

        if (! is_string($path)) {
            $this->fail('Could not create a temporary relay database.');
        }

        $this->databasePaths[] = $path;
        $pdo = $this->connection($path);
        SqliteSchema::migrate($pdo);
        $key = random_bytes(32);

        return [$pdo, $this->store($pdo, $key, $now, $workerId), $path, $key];
    }

    private function connection(string $path): PDO
    {
        return new PDO('sqlite:'.$path);
    }

    private function store(PDO $pdo, string $key, int &$now, string $workerId): SqliteRelayStore
    {
        return new SqliteRelayStore(
            $pdo,
            $key,
            $workerId,
            30,
            static function () use (&$now): int {
                return $now;
            },
        );
    }

    /** @param  array<int, string>  $senders */
    private function installation(
        array $senders = ['editor@example.com'],
        string $secret = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ): Installation {
        return new Installation(
            'si_'.str_repeat('a', 32),
            'r'.str_repeat('a', 25),
            'https://site-a.example.com/_secretary/webhooks/relay/inbound',
            $secret,
            $senders,
            true,
            'Site A',
        );
    }
}
