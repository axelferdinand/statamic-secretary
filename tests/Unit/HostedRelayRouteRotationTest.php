<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\MailTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SiteTransport;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundMessage;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Data\OutboundReply;
use AxelFerdinand\StatamicSecretaryRelay\Data\SiteDeliveryResult;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\InboundRouter;
use AxelFerdinand\StatamicSecretaryRelay\InstallationManager;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteRelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteSchema;
use AxelFerdinand\StatamicSecretaryRelay\RelayAddress;
use AxelFerdinand\StatamicSecretaryRelay\ReplyService;
use AxelFerdinand\StatamicSecretaryRelay\Security\Signature;
use PDO;
use PHPUnit\Framework\TestCase;

class HostedRelayRouteRotationTest extends TestCase
{
    public function test_route_rotation_is_retry_safe_and_preserves_only_existing_retired_threads(): void
    {
        $now = 1_800_000_000;
        [$pdo, $store] = $this->store($now);
        $installation = $this->installation();
        $store->saveInstallation($installation);
        $transport = new class implements SiteTransport
        {
            /** @var array<int, string> */
            public array $routes = [];

            private int $counter = 0;

            public function deliver(
                Installation $installation,
                InboundMessage $message,
                ?string $conversationToken,
            ): SiteDeliveryResult {
                $this->routes[] = $installation->routeToken;
                $this->counter++;

                return new SiteDeliveryResult(
                    $conversationToken ?? 'c'.str_pad((string) $this->counter, 25, 'a'),
                );
            }
        };
        $router = new InboundRouter(
            $store,
            $transport,
            new RelayAddress('secretary@statamic.no'),
        );

        $prepared = $store->prepareRouteRotation($installation->id);
        $retry = $store->prepareRouteRotation($installation->id);
        $this->assertFalse($prepared->duplicate);
        $this->assertTrue($retry->duplicate);
        $this->assertSame($prepared->rotationId, $retry->rotationId);
        $this->assertSame($prepared->routeToken, $retry->routeToken);
        $this->assertMatchesRegularExpression('/^rr_[A-Za-z0-9_-]{43}$/D', $prepared->rotationId);
        $this->assertMatchesRegularExpression('/^r[a-z0-9]{25}$/D', $prepared->routeToken);
        $this->assertSame(
            $installation->id,
            $store->installationByRouteToken($prepared->routeToken)?->id,
        );
        $this->assertSame(
            $installation->routeToken,
            $store->installationById($installation->id)?->routeToken,
        );

        try {
            $router->route($this->message('pending-new-thread', $prepared->routeToken));
            $this->fail('A pending route started a conversation.');
        } catch (RelayRejected $exception) {
            $this->assertSame(
                'Retired or pending routes cannot start a new conversation.',
                $exception->getMessage(),
            );
        }

        $oldThread = $router->route($this->message(
            'old-thread',
            $installation->routeToken,
        ));
        $this->assertSame('forwarded', $oldThread->status);
        $this->assertNotNull($oldThread->conversationToken);

        $manager = new InstallationManager($store);
        $manager->setActive($installation->id, false);
        $this->assertSame(
            $prepared->rotationId,
            $store->installationById($installation->id)?->pendingRouteRotationId,
        );
        $manager->setActive($installation->id, true);

        $promoted = $store->promoteRouteRotation(
            $installation->id,
            $prepared->rotationId,
            900,
        );
        $promotedRetry = $store->promoteRouteRotation(
            $installation->id,
            $prepared->rotationId,
            900,
        );
        $this->assertEquals($promoted, $promotedRetry);
        $this->assertSame($prepared->routeToken, $promoted->routeToken);
        $this->assertNull($promoted->pendingRouteToken);
        $this->assertNull($promoted->pendingRouteRotationId);
        $this->assertSame($prepared->rotationId, $promoted->lastRouteRotationId);
        $this->assertSame(1_800_000_900, $promoted->routeRotationAvailableAt);
        $this->assertSame(
            $installation->id,
            $store->installationByRouteToken($installation->routeToken)?->id,
        );
        $this->assertSame(
            [
                $installation->routeToken => 'retired',
                $prepared->routeToken => 'current',
            ],
            $pdo->query(
                'SELECT route_token, status
                 FROM relay_installation_routes
                 ORDER BY status DESC',
            )->fetchAll(PDO::FETCH_KEY_PAIR),
        );

        try {
            $router->route($this->message('retired-new-thread', $installation->routeToken));
            $this->fail('A retired route started a new conversation.');
        } catch (RelayRejected $exception) {
            $this->assertSame(
                'Retired or pending routes cannot start a new conversation.',
                $exception->getMessage(),
            );
        }

        $oldFollowUp = $router->route($this->message(
            'old-follow-up',
            $installation->routeToken,
            $oldThread->conversationToken,
        ));
        $newThread = $router->route($this->message(
            'new-thread',
            $prepared->routeToken,
        ));
        $this->assertSame('forwarded', $oldFollowUp->status);
        $this->assertSame($oldThread->conversationToken, $oldFollowUp->conversationToken);
        $this->assertSame('forwarded', $newThread->status);
        $this->assertSame(
            [
                $installation->routeToken,
                $installation->routeToken,
                $prepared->routeToken,
            ],
            $transport->routes,
        );

        $mail = new class implements MailTransport
        {
            public ?OutboundReply $reply = null;

            public function send(OutboundReply $reply): string
            {
                $this->reply = $reply;

                return 'postmark-route-rotation';
            }
        };
        $replyBody = json_encode([
            'version' => 1,
            'idempotency_key' => 'secretary-reply-'.str_repeat('a', 24),
            'inbound_provider_message_id' => 'old-thread',
            'recipient' => 'editor@example.com',
            'subject' => 'Re: Oppdater',
            'body' => 'Utkastet er klart.',
            'review_url' => 'https://site.example.com/cp/secretary/thread',
            'change_sets' => [],
            'route_token' => $installation->routeToken,
            'conversation_token' => $oldThread->conversationToken,
            'in_reply_to' => '<old-thread@example.com>',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $replyService = new ReplyService(
            $store,
            $mail,
            new RelayAddress('secretary@statamic.no'),
        );
        $replyService->accept(
            Signature::headers(
                $promoted,
                'POST',
                '/v1/replies',
                $replyBody,
                $now,
                str_repeat('n', 32),
            ),
            'POST',
            '/v1/replies',
            $replyBody,
            $now,
        );
        $this->assertSame(
            'secretary+'.$installation->routeToken.'.'.$oldThread->conversationToken.'@statamic.no',
            $mail->reply?->replyTo,
        );

        try {
            $store->prepareRouteRotation($installation->id);
            $this->fail('A route rotation was stacked during its transition period.');
        } catch (RelayRejected $exception) {
            $this->assertSame(
                'The previous route rotation is still in its transition period.',
                $exception->getMessage(),
            );
        }

        $now = 1_800_000_901;
        $next = $store->prepareRouteRotation($installation->id);
        $this->assertNotSame($prepared->rotationId, $next->rotationId);
        $this->assertNotSame($prepared->routeToken, $next->routeToken);
    }

    public function test_route_promotion_rejects_wrong_identity_and_transition_windows(): void
    {
        $now = 1_810_000_000;
        [, $store] = $this->store($now);
        $installation = $this->installation();
        $store->saveInstallation($installation);
        $rotation = $store->prepareRouteRotation($installation->id);

        foreach ([
            ['rr_'.str_repeat('z', 43), 300],
            [$rotation->rotationId, 299],
            [$rotation->rotationId, 3601],
        ] as [$rotationId, $transitionSeconds]) {
            try {
                $store->promoteRouteRotation(
                    $installation->id,
                    $rotationId,
                    $transitionSeconds,
                );
                $this->fail('An invalid route promotion was accepted.');
            } catch (RelayRejected) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(
            $rotation->routeToken,
            $store->installationById($installation->id)?->pendingRouteToken,
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

    private function installation(): Installation
    {
        return new Installation(
            'si_'.str_repeat('a', 32),
            'r'.str_repeat('a', 25),
            'https://site.example.com/_secretary/webhooks/relay/inbound',
            str_repeat('s', 32),
            ['editor@example.com'],
            true,
            'Site',
        );
    }

    private function message(
        string $id,
        string $routeToken,
        ?string $conversationToken = null,
    ): InboundMessage {
        return new InboundMessage(
            $id,
            'secretary+'.$routeToken.($conversationToken ? '.'.$conversationToken : '').'@statamic.no',
            'editor@example.com',
            'Oppdater siden.',
            'Oppdater',
            true,
            -0.1,
            '<'.$id.'@example.com>',
        );
    }
}
