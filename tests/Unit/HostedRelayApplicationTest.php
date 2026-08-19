<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\MailTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\PairingCodeTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SelectionTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SiteTransport;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundMessage;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Data\OutboundReply;
use AxelFerdinand\StatamicSecretaryRelay\Data\PairingCodeNotice;
use AxelFerdinand\StatamicSecretaryRelay\Data\SelectionNotice;
use AxelFerdinand\StatamicSecretaryRelay\Data\SiteDeliveryResult;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRateLimited;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayTransientFailure;
use AxelFerdinand\StatamicSecretaryRelay\HostedRelayApplication;
use AxelFerdinand\StatamicSecretaryRelay\InboundRouter;
use AxelFerdinand\StatamicSecretaryRelay\PairingService;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteRelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteSchema;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkInboundAdapter;
use AxelFerdinand\StatamicSecretaryRelay\RateLimiter;
use AxelFerdinand\StatamicSecretaryRelay\RelayAddress;
use AxelFerdinand\StatamicSecretaryRelay\ReplyService;
use AxelFerdinand\StatamicSecretaryRelay\Security\BasicAuth;
use AxelFerdinand\StatamicSecretaryRelay\Security\PublicHttpsUrl;
use AxelFerdinand\StatamicSecretaryRelay\Security\Signature;
use AxelFerdinand\StatamicSecretaryRelay\SelectionService;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

class HostedRelayApplicationTest extends TestCase
{
    private ?string $databasePath = null;

    protected function tearDown(): void
    {
        if ($this->databasePath === null) {
            return;
        }

        foreach ([$this->databasePath, $this->databasePath.'-shm', $this->databasePath.'-wal'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_postmark_webhook_requires_basic_auth_and_hides_permanent_rejections(): void
    {
        [$application] = $this->application();
        $body = json_encode($this->postmarkPayload(), JSON_THROW_ON_ERROR);

        $unauthorized = $application->postmarkInbound(['Content-Type' => 'application/json'], $body);
        $wrongContentType = $application->postmarkInbound(
            $this->postmarkHeaders(['Content-Type' => 'text/plain']),
            $body,
        );
        $invalidSender = $application->postmarkInbound(
            $this->postmarkHeaders(),
            json_encode($this->postmarkPayload(['FromFull' => ['Email' => 'unknown@example.com']]), JSON_THROW_ON_ERROR),
        );

        $this->assertSame(401, $unauthorized->status);
        $this->assertSame('Basic realm="Secretary relay"', $unauthorized->headers['WWW-Authenticate']);
        $this->assertSame(200, $wrongContentType->status);
        $this->assertSame(['accepted' => false, 'status' => 'rejected'], $this->decoded($wrongContentType->body));
        $this->assertSame(200, $invalidSender->status);
        $this->assertSame(['accepted' => false, 'status' => 'rejected'], $this->decoded($invalidSender->body));
        $this->assertStringNotContainsString('unknown@example.com', $invalidSender->body);
    }

    public function test_postmark_webhook_forwards_once_and_returns_a_safe_duplicate_response(): void
    {
        [$application, $store, $site, , $reports] = $this->application();
        $body = json_encode($this->postmarkPayload(), JSON_THROW_ON_ERROR);

        $first = $application->postmarkInbound($this->postmarkHeaders(), $body);
        $duplicate = $application->postmarkInbound($this->postmarkHeaders(), $body);

        $this->assertSame(200, $first->status);
        $this->assertSame(['accepted' => true, 'status' => 'forwarded'], $this->decoded($first->body));
        $this->assertSame(200, $duplicate->status);
        $this->assertSame(['accepted' => true, 'status' => 'duplicate'], $this->decoded($duplicate->body));
        $this->assertCount(1, $site->deliveries);
        $this->assertNotNull($store->inboundDelivery('postmark-inbound-1'));
        $this->assertSame([], $reports->exceptions);
    }

    public function test_postmark_inbound_domain_recipient_routes_by_exact_public_alias(): void
    {
        [$application, $store, $site, , $reports] = $this->application();
        $body = json_encode($this->postmarkPayload([
            'MessageID' => 'postmark-direct-alias-1',
            'MailboxHash' => '',
            'ToFull' => [[
                'Email' => 'site-a.example.com@statamic.no',
                'Name' => '',
                'MailboxHash' => '',
            ]],
        ]), JSON_THROW_ON_ERROR);

        $response = $application->postmarkInbound($this->postmarkHeaders(), $body);

        $this->assertSame(200, $response->status);
        $this->assertSame(['accepted' => true, 'status' => 'forwarded'], $this->decoded($response->body));
        $this->assertSame(['postmark-direct-alias-1'], $site->deliveries);
        $this->assertSame(
            $this->installation()->id,
            $store->inboundDelivery('postmark-direct-alias-1')?->installationId,
        );
        $this->assertSame([], $reports->exceptions);
    }

    public function test_forwarded_email_replies_continue_one_conversation_when_top_level_hash_is_empty(): void
    {
        [$application, $store, $site] = $this->application();
        $firstBody = json_encode($this->postmarkPayload(), JSON_THROW_ON_ERROR);
        $first = $application->postmarkInbound($this->postmarkHeaders(), $firstBody);
        $conversationToken = $store->inboundDelivery('postmark-inbound-1')?->conversationToken;
        $this->assertIsString($conversationToken);
        $hash = $this->installation()->routeToken.'.'.$conversationToken;

        $secondBody = json_encode($this->postmarkPayload([
            'MessageID' => 'postmark-inbound-2',
            'MailboxHash' => '',
            'StrippedTextReply' => 'Bruk forslag nummer én.',
            'ToFull' => [[
                'Email' => 'secretary+'.$hash.'@statamic.no',
                'Name' => '',
                'MailboxHash' => $hash,
            ]],
            'Headers' => [
                ['Name' => 'X-Spam-Score', 'Value' => '-0.1'],
                ['Name' => 'X-Spam-Tests', 'Value' => 'DKIM_VALID_AU,SPF_PASS'],
                ['Name' => 'Message-ID', 'Value' => '<postmark-inbound-2@example.com>'],
            ],
        ]), JSON_THROW_ON_ERROR);
        $second = $application->postmarkInbound($this->postmarkHeaders(), $secondBody);

        $thirdBody = json_encode($this->postmarkPayload([
            'MessageID' => 'postmark-inbound-3',
            'MailboxHash' => '',
            'StrippedTextReply' => 'Forsiden.',
            'ToFull' => [[
                'Email' => 'secretary+'.$hash.'@statamic.no',
                'Name' => '',
                'MailboxHash' => $hash,
            ]],
            'Headers' => [
                ['Name' => 'X-Spam-Score', 'Value' => '-0.1'],
                ['Name' => 'X-Spam-Tests', 'Value' => 'DKIM_VALID_AU,SPF_PASS'],
                ['Name' => 'Message-ID', 'Value' => '<postmark-inbound-3@example.com>'],
            ],
        ]), JSON_THROW_ON_ERROR);
        $third = $application->postmarkInbound($this->postmarkHeaders(), $thirdBody);

        $this->assertSame(['accepted' => true, 'status' => 'forwarded'], $this->decoded($first->body));
        $this->assertSame(['accepted' => true, 'status' => 'forwarded'], $this->decoded($second->body));
        $this->assertSame(['accepted' => true, 'status' => 'forwarded'], $this->decoded($third->body));
        $this->assertSame($conversationToken, $store->inboundDelivery('postmark-inbound-2')?->conversationToken);
        $this->assertSame($conversationToken, $store->inboundDelivery('postmark-inbound-3')?->conversationToken);
        $this->assertSame(
            ['postmark-inbound-1', 'postmark-inbound-2', 'postmark-inbound-3'],
            $site->deliveries,
        );
    }

    public function test_transient_site_failure_returns_retryable_status_and_releases_the_claim(): void
    {
        $site = new ApplicationSiteTransport;
        $site->failNext = true;
        [$application, , , , $reports] = $this->application($site);
        $body = json_encode($this->postmarkPayload(), JSON_THROW_ON_ERROR);

        $failed = $application->postmarkInbound($this->postmarkHeaders(), $body);
        $retried = $application->postmarkInbound($this->postmarkHeaders(), $body);

        $this->assertSame(503, $failed->status);
        $this->assertSame('30', $failed->headers['Retry-After']);
        $this->assertSame(['accepted' => false, 'status' => 'temporary_failure'], $this->decoded($failed->body));
        $this->assertSame(200, $retried->status);
        $this->assertSame(['accepted' => true, 'status' => 'forwarded'], $this->decoded($retried->body));
        $this->assertCount(1, $reports->exceptions);
        $this->assertInstanceOf(RelayTransientFailure::class, $reports->exceptions[0]);
    }

    public function test_ambiguous_plain_address_notifies_once_and_forwards_to_no_site(): void
    {
        [$application, $store, $site, , , $selection] = $this->application();
        $store->saveInstallation($this->installationB());
        $body = json_encode($this->postmarkPayload([
            'MessageID' => 'postmark-selection-1',
            'MailboxHash' => '',
            'StrippedTextReply' => 'Hemmelig instruks som ikke må videresendes.',
            'Headers' => [
                ['Name' => 'X-Spam-Score', 'Value' => '-0.1'],
                ['Name' => 'X-Spam-Tests', 'Value' => 'DKIM_VALID_AU,SPF_PASS'],
                ['Name' => 'Message-ID', 'Value' => '<postmark-selection-1@example.com>'],
            ],
        ]), JSON_THROW_ON_ERROR);

        $first = $application->postmarkInbound($this->postmarkHeaders(), $body);
        $duplicate = $application->postmarkInbound($this->postmarkHeaders(), $body);

        $this->assertSame(200, $first->status);
        $this->assertSame(['accepted' => false, 'status' => 'selection_required'], $this->decoded($first->body));
        $this->assertSame(200, $duplicate->status);
        $this->assertSame(['accepted' => false, 'status' => 'selection_required'], $this->decoded($duplicate->body));
        $this->assertCount(0, $site->deliveries);
        $this->assertCount(1, $selection->notices);
        $this->assertSame('editor@example.com', $selection->notices[0]->recipient);
        $this->assertEqualsCanonicalizing([
            'secretary+'.$this->installation()->routeToken.'@statamic.no',
            'secretary+'.$this->installationB()->routeToken.'@statamic.no',
        ], array_column($selection->notices[0]->candidates, 'address'));
    }

    public function test_signed_reply_is_sent_once_and_invalid_signatures_are_rejected(): void
    {
        [$application, $store, , $mail] = $this->application();
        $application->postmarkInbound(
            $this->postmarkHeaders(),
            json_encode($this->postmarkPayload(), JSON_THROW_ON_ERROR),
        );
        $conversationToken = $store->inboundDelivery('postmark-inbound-1')?->conversationToken;
        $this->assertIsString($conversationToken);
        $payload = $this->replyPayload($conversationToken);
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $first = $application->reply(
            $this->replyHeaders($body),
            'POST',
            '/v1/replies',
            $body,
        );
        $duplicate = $application->reply(
            $this->replyHeaders($body),
            'POST',
            '/v1/replies',
            $body,
        );
        $invalidHeaders = $this->replyHeaders($body);
        $invalidHeaders['Secretary-Signature'] = 'v1='.str_repeat('0', 64);
        $invalid = $application->reply($invalidHeaders, 'POST', '/v1/replies', $body);

        $this->assertSame(200, $first->status);
        $this->assertSame([
            'accepted' => true,
            'status' => 'sent',
            'provider_message_id' => 'postmark-reply-1',
        ], $this->decoded($first->body));
        $this->assertSame(200, $duplicate->status);
        $this->assertSame('duplicate', $this->decoded($duplicate->body)['status']);
        $this->assertSame(422, $invalid->status);
        $this->assertSame(['accepted' => false, 'status' => 'rejected'], $this->decoded($invalid->body));
        $this->assertCount(1, $mail->replies);
    }

    public function test_processing_and_transient_reply_states_remain_retryable(): void
    {
        $mail = new ApplicationMailTransport;
        [$application, $store] = $this->application(mail: $mail);
        $application->postmarkInbound(
            $this->postmarkHeaders(),
            json_encode($this->postmarkPayload(), JSON_THROW_ON_ERROR),
        );
        $conversationToken = $store->inboundDelivery('postmark-inbound-1')?->conversationToken;
        $this->assertIsString($conversationToken);
        $payload = $this->replyPayload($conversationToken);
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $key = $payload['idempotency_key'];
        $store->claimReply($key, $this->installation()->id, hash('sha256', $body));

        $processing = $application->reply($this->replyHeaders($body), 'POST', '/v1/replies', $body);
        $this->assertSame(503, $processing->status);
        $this->assertSame('5', $processing->headers['Retry-After']);

        $store->releaseReply($key, $this->installation()->id);
        $mail->failNext = true;
        $temporaryFailure = $application->reply($this->replyHeaders($body), 'POST', '/v1/replies', $body);
        $retried = $application->reply($this->replyHeaders($body), 'POST', '/v1/replies', $body);

        $this->assertSame(503, $temporaryFailure->status);
        $this->assertSame('temporary_failure', $this->decoded($temporaryFailure->body)['status']);
        $this->assertSame(200, $retried->status);
        $this->assertSame('sent', $this->decoded($retried->body)['status']);
        $this->assertCount(1, $mail->replies);
    }

    public function test_pairing_code_is_one_time_retry_safe_and_never_stored_in_plaintext(): void
    {
        [$application, $store, , , , , $pairings] = $this->application();
        $issued = $pairings->issue('Nytt nettsted', ['owner@example.com']);
        $payload = [
            'version' => 1,
            'pairing_code' => $issued->code,
            'claim_id' => 'pci_'.str_repeat('a', 22),
            'webhook_url' => 'https://paired.example.com/_secretary/webhooks/relay/inbound',
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json'];

        $first = $application->pairing($headers, $body);
        $duplicate = $application->pairing($headers, $body);
        $changedPayload = $payload;
        $changedPayload['claim_id'] = 'pci_'.str_repeat('b', 22);
        $changed = $application->pairing(
            $headers,
            json_encode($changedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );

        $firstPayload = $this->decoded($first->body);
        $duplicatePayload = $this->decoded($duplicate->body);
        $this->assertSame(201, $first->status);
        $this->assertSame('paired', $firstPayload['status']);
        $this->assertSame(200, $duplicate->status);
        $this->assertSame('already_paired', $duplicatePayload['status']);
        $this->assertSame($firstPayload['installation_id'], $duplicatePayload['installation_id']);
        $this->assertSame($firstPayload['route_token'], $duplicatePayload['route_token']);
        $this->assertSame($firstPayload['signing_secret'], $duplicatePayload['signing_secret']);
        $this->assertSame(422, $changed->status);
        $this->assertSame('rejected', $this->decoded($changed->body)['status']);
        $this->assertMatchesRegularExpression('/^si_[a-z0-9_-]{32}$/D', $firstPayload['installation_id']);
        $this->assertMatchesRegularExpression('/^r[a-z0-9]{25}$/D', $firstPayload['route_token']);
        $secret = base64_decode(strtr($firstPayload['signing_secret'], '-_', '+/').'=', true);
        $this->assertIsString($secret);
        $this->assertSame(32, strlen($secret));
        $installation = $store->installationById($firstPayload['installation_id']);
        $this->assertNotNull($installation);
        $this->assertSame(['owner@example.com'], $installation->senders);

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $stored = $pdo->query('SELECT code_digest FROM relay_pairing_codes')->fetchColumn();
        $this->assertSame(hash('sha256', $issued->code), $stored);
        $databaseBytes = file_get_contents($this->databasePath);
        $this->assertIsString($databaseBytes);
        $this->assertStringNotContainsString($issued->code, $databaseBytes);
    }

    public function test_an_authorized_sender_can_request_a_pairing_code_without_exposing_it_in_the_response_or_database(): void
    {
        [$application, , , , , , , $pairingCodes] = $this->application();
        $body = json_encode([
            'version' => 1,
            'email' => 'Owner@Example.com',
            'label' => '  Kunde   X  ',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $result = $application->requestPairingCode(
            ['Content-Type' => 'application/json'],
            $body,
            '203.0.113.42',
        );

        $this->assertSame(202, $result->status);
        $this->assertSame(
            ['accepted' => true, 'status' => 'verification_sent'],
            $this->decoded($result->body),
        );
        $this->assertCount(1, $pairingCodes->notices);
        $notice = $pairingCodes->notices[0];
        $this->assertSame('owner@example.com', $notice->recipient);
        $this->assertSame('Kunde X', $notice->label);
        $this->assertMatchesRegularExpression('/^pc_[A-Za-z0-9_-]{43}$/D', $notice->code);
        $this->assertGreaterThan(time() + 800, $notice->expiresAt);
        $this->assertStringNotContainsString($notice->code, $result->body);

        $databaseBytes = file_get_contents($this->databasePath);
        $this->assertIsString($databaseBytes);
        $this->assertStringNotContainsString($notice->code, $databaseBytes);
    }

    public function test_pairing_code_requests_reject_invalid_shapes_and_rate_limit_each_recipient(): void
    {
        [$application, , , , , , , $pairingCodes] = $this->application(
            rateLimits: [
                'pairing_request_source' => 10,
                'pairing_recipient' => 1,
            ],
        );
        $headers = ['Content-Type' => 'application/json'];
        $valid = [
            'version' => 1,
            'email' => 'owner@example.com',
            'label' => 'Kunde X',
        ];
        $invalid = $valid;
        $invalid['unexpected'] = true;

        $rejected = $application->requestPairingCode(
            $headers,
            json_encode($invalid, JSON_THROW_ON_ERROR),
            '203.0.113.42',
        );
        $first = $application->requestPairingCode(
            $headers,
            json_encode($valid, JSON_THROW_ON_ERROR),
            '203.0.113.42',
        );
        $blocked = $application->requestPairingCode(
            $headers,
            json_encode($valid, JSON_THROW_ON_ERROR),
            '203.0.113.43',
        );
        $other = $valid;
        $other['email'] = 'other@example.com';
        $otherRecipient = $application->requestPairingCode(
            $headers,
            json_encode($other, JSON_THROW_ON_ERROR),
            '203.0.113.43',
        );

        $this->assertSame(422, $rejected->status);
        $this->assertSame(202, $first->status);
        $this->assertSame(429, $blocked->status);
        $this->assertSame('rate_limited', $this->decoded($blocked->body)['status']);
        $this->assertSame(202, $otherRecipient->status);
        $this->assertCount(2, $pairingCodes->notices);
        $this->assertSame(
            ['owner@example.com', 'other@example.com'],
            array_map(
                static fn (PairingCodeNotice $notice): string => $notice->recipient,
                $pairingCodes->notices,
            ),
        );

        $databaseBytes = file_get_contents($this->databasePath);
        $this->assertIsString($databaseBytes);
        $this->assertStringNotContainsString('203.0.113.42', $databaseBytes);
        $this->assertStringNotContainsString('203.0.113.43', $databaseBytes);
    }

    public function test_pairing_rejects_unknown_codes_private_webhooks_and_extra_fields(): void
    {
        [$application, , , , , , $pairings] = $this->application();
        $issued = $pairings->issue('Nytt nettsted', ['owner@example.com']);
        $valid = [
            'version' => 1,
            'pairing_code' => $issued->code,
            'claim_id' => 'pci_'.str_repeat('a', 22),
            'webhook_url' => 'https://paired.example.com/_secretary/webhooks/relay/inbound',
        ];
        $invalid = [
            [...$valid, 'pairing_code' => 'pc_'.str_repeat('x', 43)],
            [...$valid, 'webhook_url' => 'https://127.0.0.1/_secretary/webhooks/relay/inbound'],
            [...$valid, 'unexpected' => true],
        ];

        foreach ($invalid as $payload) {
            $result = $application->pairing(
                ['Content-Type' => 'application/json'],
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
            $this->assertSame(422, $result->status);
            $this->assertSame(['accepted' => false, 'status' => 'rejected'], $this->decoded($result->body));
        }
    }

    public function test_pairing_rate_limit_is_source_scoped_hashed_and_retryable(): void
    {
        [$application, , , , $reports] = $this->application(
            rateLimits: ['pairing_source' => 2],
        );
        $body = json_encode([
            'version' => 1,
            'pairing_code' => 'pc_'.str_repeat('x', 43),
            'claim_id' => 'pci_'.str_repeat('a', 22),
            'webhook_url' => 'https://paired.example.com/_secretary/webhooks/relay/inbound',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json'];
        $client = '203.0.113.42';

        $first = $application->pairing($headers, $body, $client);
        $second = $application->pairing($headers, $body, $client);
        $blocked = $application->pairing($headers, $body, $client);
        $otherClient = $application->pairing($headers, $body, '203.0.113.43');

        $this->assertSame(422, $first->status);
        $this->assertSame(422, $second->status);
        $this->assertSame(429, $blocked->status);
        $this->assertSame(
            ['accepted' => false, 'status' => 'rate_limited'],
            $this->decoded($blocked->body),
        );
        $this->assertSame('0', $blocked->headers['X-RateLimit-Remaining']);
        $this->assertGreaterThanOrEqual(1, (int) $blocked->headers['Retry-After']);
        $this->assertLessThanOrEqual(60, (int) $blocked->headers['Retry-After']);
        $this->assertSame(422, $otherClient->status);
        $this->assertInstanceOf(
            RelayRateLimited::class,
            $reports->exceptions[2],
        );
        $this->assertSame(
            'pairing_source',
            $reports->exceptions[2]->scope,
        );
        $databaseBytes = file_get_contents($this->databasePath);
        $this->assertIsString($databaseBytes);
        $this->assertStringNotContainsString($client, $databaseBytes);
    }

    /**
     * @return array{
     *     HostedRelayApplication,
     *     SqliteRelayStore,
     *     ApplicationSiteTransport,
     *     ApplicationMailTransport,
     *     ApplicationReportCollector,
     *     ApplicationSelectionTransport,
     *     PairingService,
     *     ApplicationPairingCodeTransport
     * }
     */
    private function application(
        ?ApplicationSiteTransport $site = null,
        ?ApplicationMailTransport $mail = null,
        ?array $rateLimits = null,
    ): array {
        $path = tempnam(sys_get_temp_dir(), 'statamic-secretary-relay-app-');

        if (! is_string($path)) {
            $this->fail('Could not create a relay application database.');
        }

        $this->databasePath = $path;
        $pdo = new PDO('sqlite:'.$path);
        SqliteSchema::migrate($pdo);
        $store = new SqliteRelayStore(
            $pdo,
            random_bytes(32),
            str_repeat('w', 22),
        );
        $store->saveInstallation($this->installation());
        $site ??= new ApplicationSiteTransport;
        $mail ??= new ApplicationMailTransport;
        $reports = new ApplicationReportCollector;
        $selection = new ApplicationSelectionTransport;
        $pairingCodes = new ApplicationPairingCodeTransport;
        $address = new RelayAddress('secretary@statamic.no');
        $pairings = new PairingService(
            $store,
            $address,
            new PublicHttpsUrl(static fn (): array => ['8.8.8.8']),
        );
        $application = new HostedRelayApplication(
            new BasicAuth('postmark_webhook', str_repeat('p', 32)),
            new PostmarkInboundAdapter('secretary@statamic.no'),
            new InboundRouter($store, $site, $address),
            new ReplyService($store, $mail, $address),
            new SelectionService($store, $store, $selection, $address),
            $pairings,
            $pairingCodes,
            $reports,
            rateLimiter: $rateLimits === null
                ? null
                : new RateLimiter($store, $rateLimits),
        );

        return [$application, $store, $site, $mail, $reports, $selection, $pairings, $pairingCodes];
    }

    /** @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function postmarkHeaders(array $overrides = []): array
    {
        return [
            'Authorization' => 'basic '.base64_encode('postmark_webhook:'.str_repeat('p', 32)),
            'Content-Type' => 'application/json; charset=utf-8',
            ...$overrides,
        ];
    }

    /** @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function postmarkPayload(array $overrides = []): array
    {
        return [
            'MessageID' => 'postmark-inbound-1',
            'MailboxHash' => $this->installation()->routeToken,
            'Subject' => 'Endre forsiden',
            'StrippedTextReply' => 'Oppdater forsiden.',
            'TextBody' => 'Oppdater forsiden.',
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => [
                ['Name' => 'X-Spam-Score', 'Value' => '-0.1'],
                ['Name' => 'X-Spam-Tests', 'Value' => 'DKIM_VALID_AU,SPF_PASS'],
                ['Name' => 'Message-ID', 'Value' => '<postmark-inbound-1@example.com>'],
            ],
            'Attachments' => [],
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function replyPayload(string $conversationToken): array
    {
        return [
            'version' => 1,
            'idempotency_key' => 'secretary-reply-'.str_repeat('a', 24),
            'inbound_provider_message_id' => 'postmark-inbound-1',
            'recipient' => 'editor@example.com',
            'subject' => 'Re: Endre forsiden',
            'body' => 'Utkastet er klart.',
            'review_url' => 'https://site-a.example.com/cp/secretary/thread',
            'change_sets' => [],
            'route_token' => $this->installation()->routeToken,
            'conversation_token' => $conversationToken,
            'in_reply_to' => '<postmark-inbound-1@example.com>',
        ];
    }

    /** @return array<string, string> */
    private function replyHeaders(string $body): array
    {
        return [
            ...Signature::headers($this->installation(), 'POST', '/v1/replies', $body),
            'Content-Type' => 'application/json',
        ];
    }

    /** @return array<string, mixed> */
    private function decoded(string $body): array
    {
        return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }

    private function installation(): Installation
    {
        return new Installation(
            'si_'.str_repeat('a', 32),
            'r'.str_repeat('a', 25),
            'https://site-a.example.com/_secretary/webhooks/relay/inbound',
            str_repeat('s', 32),
            ['editor@example.com'],
            true,
            'Site A',
            publicAlias: 'site-a.example.com',
        );
    }

    private function installationB(): Installation
    {
        return new Installation(
            'si_'.str_repeat('b', 32),
            'r'.str_repeat('b', 25),
            'https://site-b.example.com/_secretary/webhooks/relay/inbound',
            str_repeat('b', 32),
            ['editor@example.com'],
            true,
            'Site B',
            publicAlias: 'site-b.example.com',
        );
    }
}

final class ApplicationSiteTransport implements SiteTransport
{
    /** @var array<int, string> */
    public array $deliveries = [];

    public bool $failNext = false;

    public function deliver(
        Installation $installation,
        InboundMessage $message,
        ?string $conversationToken,
        bool $acknowledgementSent = false,
    ): SiteDeliveryResult {
        if ($this->failNext) {
            $this->failNext = false;

            throw new RelayTransientFailure('Simulated site outage.');
        }

        $conversationToken ??= 'c'.str_repeat('c', 25);
        $this->deliveries[] = $message->providerMessageId;

        return new SiteDeliveryResult($conversationToken);
    }
}

final class ApplicationMailTransport implements MailTransport
{
    /** @var array<int, OutboundReply> */
    public array $replies = [];

    public bool $failNext = false;

    public function send(OutboundReply $reply): string
    {
        if ($this->failNext) {
            $this->failNext = false;

            throw new RelayTransientFailure('Simulated Postmark outage.');
        }

        $this->replies[] = $reply;

        return 'postmark-reply-'.count($this->replies);
    }
}

final class ApplicationReportCollector
{
    /** @var array<int, Throwable> */
    public array $exceptions = [];

    public function __invoke(Throwable $exception): void
    {
        $this->exceptions[] = $exception;
    }
}

final class ApplicationSelectionTransport implements SelectionTransport
{
    /** @var array<int, SelectionNotice> */
    public array $notices = [];

    public function send(SelectionNotice $notice): string
    {
        $this->notices[] = $notice;

        return 'postmark-selection-'.count($this->notices);
    }
}

final class ApplicationPairingCodeTransport implements PairingCodeTransport
{
    /** @var array<int, PairingCodeNotice> */
    public array $notices = [];

    public function send(PairingCodeNotice $notice): string
    {
        $this->notices[] = $notice;

        return 'postmark-pairing-'.count($this->notices);
    }
}
