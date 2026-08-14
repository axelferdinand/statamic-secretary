<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\SiteTransport;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundMessage;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Data\SiteDeliveryResult;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\InboundRouter;
use AxelFerdinand\StatamicSecretaryRelay\InstallationManager;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteRelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteSchema;
use AxelFerdinand\StatamicSecretaryRelay\RelayAddress;
use PDO;
use PHPUnit\Framework\TestCase;

class HostedRelayAdministrationTest extends TestCase
{
    public function test_an_operator_disables_and_reenables_an_installation_without_changing_its_identity(): void
    {
        [$pdo, $store] = $this->store();
        $installation = $this->installation();
        $store->saveInstallation($installation);
        $manager = new InstallationManager($store);

        $disabled = $manager->setActive($installation->id, false);

        $this->assertFalse($disabled->active);
        $this->assertSame($installation->id, $disabled->id);
        $this->assertSame($installation->routeToken, $disabled->routeToken);
        $this->assertSame($installation->signingSecret, $disabled->signingSecret);
        $this->assertSame($installation->webhookUrl, $disabled->webhookUrl);
        $this->assertSame($installation->senders, $disabled->senders);
        $this->assertFalse($store->installationById($installation->id)?->active);
        $this->assertStringNotContainsString(
            $installation->signingSecret,
            (string) $pdo->query('SELECT signing_secret_ciphertext FROM relay_installations')->fetchColumn(),
        );

        $transport = new class implements SiteTransport
        {
            public int $deliveries = 0;

            public function deliver(
                Installation $installation,
                InboundMessage $message,
                ?string $conversationToken,
                bool $acknowledgementSent = false,
            ): SiteDeliveryResult {
                $this->deliveries++;

                return new SiteDeliveryResult('c'.str_repeat('c', 25));
            }
        };
        $router = new InboundRouter(
            $store,
            $transport,
            new RelayAddress('secretary@statamic.no'),
        );

        try {
            $router->route($this->message($installation->routeToken));
            $this->fail('A disabled installation received a message.');
        } catch (RelayRejected $exception) {
            $this->assertSame('Route is not available to this sender.', $exception->getMessage());
        }
        $this->assertSame(0, $transport->deliveries);

        $enabled = $manager->setActive($installation->id, true);
        $this->assertTrue($enabled->active);
        $this->assertSame('forwarded', $router->route($this->message($installation->routeToken, 'message-b'))->status);
        $this->assertSame(1, $transport->deliveries);
    }

    public function test_sender_changes_are_normalized_durable_and_idempotent(): void
    {
        [, $store] = $this->store();
        $installation = $this->installation();
        $store->saveInstallation($installation);
        $manager = new InstallationManager($store);

        $added = $manager->addSender($installation->id, ' OWNER@Example.com ');
        $this->assertSame(['editor@example.com', 'owner@example.com'], $added->senders);
        $this->assertEquals($added, $manager->addSender($installation->id, 'owner@example.com'));
        $this->assertCount(1, $store->installationsForSender('owner@example.com'));

        $removed = $manager->removeSender($installation->id, 'EDITOR@example.com');
        $this->assertSame(['owner@example.com'], $removed->senders);
        $this->assertEquals($removed, $manager->removeSender($installation->id, 'editor@example.com'));
        $this->assertSame([], $store->installationsForSender('editor@example.com'));
        $this->assertCount(1, $store->installationsForSender('owner@example.com'));
    }

    public function test_management_rejects_invalid_installations_and_senders(): void
    {
        [, $store] = $this->store();
        $manager = new InstallationManager($store);
        $store->saveInstallation($this->installation());

        foreach ([
            fn () => $manager->status('not-an-installation'),
            fn () => $manager->status('si_'.str_repeat('z', 32)),
            fn () => $manager->addSender($this->installation()->id, 'not-an-email'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('An invalid administration request was accepted.');
            } catch (RelayRejected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array{PDO, SqliteRelayStore} */
    private function store(): array
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
                static fn (): int => 1000,
            ),
        ];
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
        );
    }

    private function message(string $routeToken, string $id = 'message-a'): InboundMessage
    {
        return new InboundMessage(
            $id,
            'secretary+'.$routeToken.'@statamic.no',
            'editor@example.com',
            'Oppdater siden.',
            'Oppdater',
            true,
            -0.1,
            '<'.$id.'@example.com>',
        );
    }
}
