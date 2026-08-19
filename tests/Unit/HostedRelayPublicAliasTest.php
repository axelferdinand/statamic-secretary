<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\PublicAliasProvisioner;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\InboundDomainPublicAliasProvisioner;
use AxelFerdinand\StatamicSecretaryRelay\PairingService;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteRelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteSchema;
use AxelFerdinand\StatamicSecretaryRelay\PublicSiteAlias;
use AxelFerdinand\StatamicSecretaryRelay\RelayAddress;
use AxelFerdinand\StatamicSecretaryRelay\Security\PublicHttpsUrl;
use PDO;
use PHPUnit\Framework\TestCase;

class HostedRelayPublicAliasTest extends TestCase
{
    private string $databasePath;

    protected function tearDown(): void
    {
        foreach ([$this->databasePath ?? '', ($this->databasePath ?? '').'-shm', ($this->databasePath ?? '').'-wal'] as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_pairing_provisions_a_stable_domain_alias_without_exposing_the_route(): void
    {
        $store = $this->store();
        $provisioner = new MemoryPublicAliasProvisioner;
        $service = new PairingService(
            $store,
            new RelayAddress('secretary@statamic.no'),
            new PublicHttpsUrl(static fn (): array => ['8.8.8.8']),
            $provisioner,
        );
        $issued = $service->issue('Kundenettsted', ['owner@example.com']);
        $body = json_encode([
            'version' => 1,
            'pairing_code' => $issued->code,
            'claim_id' => 'pci_'.str_repeat('a', 22),
            'webhook_url' => 'https://www.kundedomenet.no/_secretary/webhooks/relay/inbound',
        ], JSON_THROW_ON_ERROR);

        $first = $service->claim($body);
        $retry = $service->claim($body);
        $response = $service->response($first);

        $this->assertSame('kundedomenet.no', $first->installation->publicAlias);
        $this->assertSame('kundedomenet.no@statamic.no', $response['address']);
        $this->assertSame(
            'secretary+'.$first->installation->routeToken.'@statamic.no',
            $response['route_address'],
        );
        $this->assertSame($first->installation->id, $retry->installation->id);
        $this->assertCount(2, $provisioner->installations);
        $this->assertSame(
            $provisioner->installations[0]->publicAlias,
            $provisioner->installations[1]->publicAlias,
        );
    }

    public function test_postmark_inbound_domain_aliases_need_no_per_address_forwarder(): void
    {
        $store = $this->store();
        $service = new PairingService(
            $store,
            new RelayAddress('secretary@statamic.no'),
            new PublicHttpsUrl(static fn (): array => ['8.8.8.8']),
            new InboundDomainPublicAliasProvisioner(new RelayAddress('secretary@statamic.no')),
        );
        $issued = $service->issue('Kundenettsted', ['owner@example.com']);
        $outcome = $service->claim($this->claimBody(
            $issued->code,
            'd',
            'https://direct.example.com/_secretary/webhooks/relay/inbound',
        ));

        $this->assertSame('direct.example.com', $outcome->installation->publicAlias);
        $this->assertSame(
            'direct.example.com@statamic.no',
            $service->response($outcome)['address'],
        );
    }

    public function test_two_installations_on_one_host_receive_collision_safe_aliases(): void
    {
        $store = $this->store();
        $service = new PairingService(
            $store,
            new RelayAddress('secretary@statamic.no'),
            new PublicHttpsUrl(static fn (): array => ['8.8.8.8']),
        );
        $first = $service->issue('Produksjon', ['owner@example.com']);
        $second = $service->issue('Ny installasjon', ['editor@example.com']);
        $webhook = 'https://site.example.com/_secretary/webhooks/relay/inbound';

        $firstOutcome = $service->claim($this->claimBody($first->code, 'a', $webhook));
        $secondOutcome = $service->claim($this->claimBody($second->code, 'b', $webhook));

        $this->assertSame('site.example.com', $firstOutcome->installation->publicAlias);
        $this->assertMatchesRegularExpression(
            '/^site\\.example\\.com-[a-z0-9]{8}$/D',
            $secondOutcome->installation->publicAlias,
        );
        $this->assertNotSame(
            $firstOutcome->installation->publicAlias,
            $secondOutcome->installation->publicAlias,
        );
    }

    public function test_repairing_the_same_site_for_the_same_sender_reuses_the_original_alias(): void
    {
        $store = $this->store();
        $provisioner = new MemoryPublicAliasProvisioner;
        $service = new PairingService(
            $store,
            new RelayAddress('secretary@statamic.no'),
            new PublicHttpsUrl(static fn (): array => ['8.8.8.8']),
            $provisioner,
        );
        $first = $service->issue('First setup', ['OWNER@example.com']);
        $second = $service->issue('Reinstalled site', ['owner@example.com']);
        $webhook = 'https://site.example.com/_secretary/webhooks/relay/inbound';

        $firstOutcome = $service->claim($this->claimBody($first->code, 'a', $webhook));
        $secondOutcome = $service->claim($this->claimBody($second->code, 'b', $webhook));

        $this->assertSame($firstOutcome->installation->id, $secondOutcome->installation->id);
        $this->assertSame($firstOutcome->installation->routeToken, $secondOutcome->installation->routeToken);
        $this->assertSame('site.example.com', $secondOutcome->installation->publicAlias);
        $this->assertTrue($secondOutcome->duplicate);
        $this->assertCount(2, $provisioner->installations);
    }

    public function test_legacy_pairing_schema_is_migrated_to_allow_reconnecting_an_installation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'statamic-secretary-legacy-pairing-');
        $this->assertIsString($path);
        $this->databasePath = $path;
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec(
            <<<'SQL'
                CREATE TABLE relay_pairing_codes (
                    code_digest TEXT PRIMARY KEY,
                    status TEXT NOT NULL CHECK (status IN ('issued', 'complete')),
                    label TEXT NOT NULL,
                    senders_json TEXT NOT NULL,
                    expires_at INTEGER NOT NULL,
                    claim_fingerprint TEXT NULL,
                    installation_id TEXT NULL UNIQUE,
                    created_at INTEGER NOT NULL,
                    claimed_at INTEGER NULL
                )
                SQL,
        );

        SqliteSchema::migrate($pdo);
        $uniqueIndexes = array_filter(
            $pdo->query('PRAGMA index_list(relay_pairing_codes)')->fetchAll(PDO::FETCH_ASSOC),
            static fn (array $index): bool => ($index['origin'] ?? null) === 'u',
        );

        $this->assertSame([], array_values($uniqueIndexes));
    }

    public function test_long_hosts_are_shortened_to_a_valid_email_local_part(): void
    {
        $host = str_repeat('long-segment-', 8).'.example.com';
        $alias = PublicSiteAlias::fromWebhookUrl(
            'https://'.$host.'/_secretary/webhooks/relay/inbound',
        );

        $this->assertLessThanOrEqual(64, strlen($alias));
        $this->assertTrue(PublicSiteAlias::valid($alias));
        $this->assertStringContainsString('-', $alias);
    }

    private function store(): SqliteRelayStore
    {
        $path = tempnam(sys_get_temp_dir(), 'statamic-secretary-alias-');
        $this->assertIsString($path);
        $this->databasePath = $path;
        $pdo = new PDO('sqlite:'.$path);
        SqliteSchema::migrate($pdo);

        return new SqliteRelayStore($pdo, random_bytes(32));
    }

    private function claimBody(string $code, string $claimCharacter, string $webhook): string
    {
        return json_encode([
            'version' => 1,
            'pairing_code' => $code,
            'claim_id' => 'pci_'.str_repeat($claimCharacter, 22),
            'webhook_url' => $webhook,
        ], JSON_THROW_ON_ERROR);
    }
}

final class MemoryPublicAliasProvisioner implements PublicAliasProvisioner
{
    /** @var array<int, Installation> */
    public array $installations = [];

    public function provision(Installation $installation): void
    {
        $this->installations[] = $installation;
    }
}
