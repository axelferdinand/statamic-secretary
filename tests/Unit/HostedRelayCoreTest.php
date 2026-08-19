<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\HttpTransportResponse;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\MailTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\RelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SiteTransport;
use AxelFerdinand\StatamicSecretaryRelay\Data\ClaimState;
use AxelFerdinand\StatamicSecretaryRelay\Data\ConversationRoute;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundDelivery;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundMessage;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Data\OutboundReply;
use AxelFerdinand\StatamicSecretaryRelay\Data\SiteDeliveryResult;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayTransientFailure;
use AxelFerdinand\StatamicSecretaryRelay\InboundRouter;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkInboundAdapter;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkMailTransport;
use AxelFerdinand\StatamicSecretaryRelay\ReceiptService;
use AxelFerdinand\StatamicSecretaryRelay\RelayAddress;
use AxelFerdinand\StatamicSecretaryRelay\ReplyService;
use AxelFerdinand\StatamicSecretaryRelay\Security\Signature;
use AxelFerdinand\StatamicSecretaryRelay\SignedSiteTransport;
use AxelFerdinand\StatamicSecretaryRelay\Tokens;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HostedRelayCoreTest extends TestCase
{
    public function test_a_tagged_address_forwards_to_exactly_one_installation(): void
    {
        [$store, $transport, $router] = $this->router();

        $outcome = $router->route($this->message('provider-a', $this->alias($this->installationA()->routeToken)));

        $this->assertSame('forwarded', $outcome->status);
        $this->assertSame($this->installationA()->id, $outcome->installationId);
        $this->assertCount(1, $transport->deliveries);
        $this->assertSame($this->installationA()->id, $transport->deliveries[0]['installation']->id);
        $this->assertNotNull($store->inboundDelivery('provider-a'));
    }

    public function test_a_direct_public_alias_forwards_to_its_exact_installation(): void
    {
        [$store, $transport, $router] = $this->router();

        $outcome = $router->route($this->message(
            'provider-direct-alias',
            'site-a.example.com@statamic.no',
        ));

        $this->assertSame('forwarded', $outcome->status);
        $this->assertSame($this->installationA()->id, $outcome->installationId);
        $this->assertCount(1, $transport->deliveries);
        $this->assertSame($this->installationA()->routeToken, $transport->deliveries[0]['installation']->routeToken);
        $this->assertNotNull($store->inboundDelivery('provider-direct-alias'));
    }

    public function test_a_direct_public_alias_never_falls_back_to_sender_routing(): void
    {
        [, $transport, $router] = $this->router();

        try {
            $router->route($this->message(
                'provider-unknown-alias',
                'unknown.example.com@statamic.no',
            ));
            $this->fail('An unknown direct alias was routed by sender membership.');
        } catch (RelayRejected $exception) {
            $this->assertSame('Public alias is not available to this sender.', $exception->getMessage());
        }

        $this->assertCount(0, $transport->deliveries);
    }

    public function test_plain_address_with_overlapping_sender_forwards_to_neither_site(): void
    {
        [, $transport, $router] = $this->router();

        $outcome = $router->route($this->message('ambiguous', 'secretary@statamic.no'));

        $this->assertSame('selection_required', $outcome->status);
        $this->assertEqualsCanonicalizing(
            [$this->installationA()->routeToken, $this->installationB()->routeToken],
            $outcome->candidateRouteTokens,
        );
        $this->assertCount(0, $transport->deliveries);
    }

    public function test_plain_address_routes_only_when_sender_matches_one_active_site(): void
    {
        $installationB = $this->installationB(senders: ['other@example.com']);
        $store = new MemoryRelayStore([$this->installationA(), $installationB]);
        $transport = new MemorySiteTransport;
        $router = new InboundRouter($store, $transport, new RelayAddress('secretary@statamic.no'));

        $outcome = $router->route($this->message('unambiguous', 'secretary@statamic.no'));

        $this->assertSame($this->installationA()->id, $outcome->installationId);
        $this->assertCount(1, $transport->deliveries);
    }

    public function test_a_conversation_token_cannot_move_to_another_route(): void
    {
        [, $transport, $router] = $this->router();
        $first = $router->route($this->message('thread-a', $this->alias($this->installationA()->routeToken)));

        try {
            $router->route($this->message(
                'thread-b',
                $this->alias($this->installationB()->routeToken, $first->conversationToken),
            ));
            $this->fail('A cross-route conversation substitution was accepted.');
        } catch (RelayRejected $exception) {
            $this->assertSame('Conversation does not belong to the selected route and sender.', $exception->getMessage());
        }

        $this->assertCount(1, $transport->deliveries);
    }

    public function test_provider_duplicates_are_idempotent_and_cannot_switch_installations(): void
    {
        [, $transport, $router] = $this->router();
        $message = $this->message('provider-duplicate', $this->alias($this->installationA()->routeToken));

        $first = $router->route($message);
        $duplicate = $router->route($message);

        $this->assertSame('forwarded', $first->status);
        $this->assertSame('duplicate', $duplicate->status);
        $this->assertCount(1, $transport->deliveries);

        $this->expectException(RelayRejected::class);
        $router->route($this->message('provider-duplicate', $this->alias($this->installationB()->routeToken)));
    }

    public function test_provider_duplicate_cannot_reuse_the_same_identity_with_changed_content(): void
    {
        [, $transport, $router] = $this->router();
        $message = $this->message('provider-content-conflict', $this->alias($this->installationA()->routeToken));

        $router->route($message);

        try {
            $router->route(new InboundMessage(
                $message->providerMessageId,
                $message->recipient,
                $message->sender,
                'Et annet innhold.',
                $message->subject,
                $message->senderAuthenticated,
                $message->spamScore,
                $message->rfcMessageId,
            ));
            $this->fail('A changed message reused an existing provider identity.');
        } catch (RelayRejected $exception) {
            $this->assertSame('Provider message identity conflicts with an existing claim.', $exception->getMessage());
        }

        $this->assertCount(1, $transport->deliveries);
    }

    public function test_a_failed_site_delivery_releases_the_claim_for_retry(): void
    {
        [, $transport, $router] = $this->router();
        $transport->failNext = true;
        $message = $this->message('retry-provider', $this->alias($this->installationA()->routeToken));

        try {
            $router->route($message);
            $this->fail('The simulated delivery failure did not escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated site failure.', $exception->getMessage());
        }

        $outcome = $router->route($message);

        $this->assertSame('forwarded', $outcome->status);
        $this->assertSame(2, $transport->attempts);
        $this->assertCount(1, $transport->deliveries);
    }

    public function test_relay_acknowledges_immediately_once_and_keeps_the_reply_thread_bound(): void
    {
        $installation = $this->installationA();
        $store = new MemoryRelayStore([$installation]);
        $site = new MemorySiteTransport;
        $mail = new MemoryMailTransport;
        $address = new RelayAddress('secretary@statamic.no');
        $router = new InboundRouter(
            $store,
            $site,
            $address,
            receipts: new ReceiptService($store, $mail, $address),
        );
        $message = $this->message('receipt-provider', $this->alias($installation->routeToken));

        $first = $router->route($message);
        $duplicate = $router->route($message);

        $this->assertSame('forwarded', $first->status);
        $this->assertSame('duplicate', $duplicate->status);
        $this->assertIsInt($first->acknowledgementMilliseconds);
        $this->assertCount(1, $mail->replies);
        $this->assertCount(1, $site->deliveries);
        $this->assertTrue($site->deliveries[0]['acknowledgement_sent']);
        $receipt = $mail->replies[0];
        $this->assertSame('nb', $receipt->locale);
        $this->assertStringContainsString('Mottatt — jeg er på saken.', $receipt->body);
        $this->assertSame(
            $this->alias($installation->routeToken, $first->conversationToken),
            $receipt->replyTo,
        );
    }

    public function test_site_retry_does_not_send_a_second_receipt(): void
    {
        $installation = $this->installationA();
        $store = new MemoryRelayStore([$installation]);
        $site = new MemorySiteTransport;
        $site->failNext = true;
        $mail = new MemoryMailTransport;
        $address = new RelayAddress('secretary@statamic.no');
        $router = new InboundRouter(
            $store,
            $site,
            $address,
            receipts: new ReceiptService($store, $mail, $address),
        );
        $message = $this->message('receipt-retry-provider', $this->alias($installation->routeToken));

        try {
            $router->route($message);
            $this->fail('The simulated delivery failure did not escape.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $outcome = $router->route($message);

        $this->assertSame('forwarded', $outcome->status);
        $this->assertCount(1, $mail->replies);
        $this->assertSame(2, $site->attempts);
    }

    public function test_inactive_unknown_and_unauthenticated_routes_are_rejected(): void
    {
        $inactive = $this->installationA(active: false);
        $store = new MemoryRelayStore([$inactive]);
        $transport = new MemorySiteTransport;
        $router = new InboundRouter($store, $transport, new RelayAddress('secretary@statamic.no'));

        foreach ([
            $this->message('inactive', $this->alias($inactive->routeToken)),
            $this->message('unknown', $this->alias('r'.str_repeat('z', 25))),
            $this->message('unauthenticated', $this->alias($inactive->routeToken), authenticated: false),
        ] as $message) {
            try {
                $router->route($message);
                $this->fail('An invalid relay message was accepted.');
            } catch (RelayRejected) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertCount(0, $transport->deliveries);
    }

    public function test_registered_sender_is_authorized_without_customer_dkim_when_policy_is_disabled(): void
    {
        $store = new MemoryRelayStore([$this->installationA()]);
        $transport = new MemorySiteTransport;
        $router = new InboundRouter(
            $store,
            $transport,
            new RelayAddress('secretary@statamic.no'),
            requireSenderAuthentication: false,
        );

        $outcome = $router->route($this->message(
            'registered-without-dkim',
            $this->alias($this->installationA()->routeToken),
            authenticated: false,
        ));

        $this->assertSame('forwarded', $outcome->status);
        $this->assertCount(1, $transport->deliveries);
        $this->assertTrue($transport->deliveries[0]['message']->senderAuthenticated);
    }

    public function test_signed_site_transport_uses_the_exact_addon_contract(): void
    {
        $token = 'c'.str_repeat('c', 25);
        $http = new MemoryHttpTransport(new HttpTransportResponse(202, json_encode([
            'accepted' => true,
            'conversation_token' => $token,
        ], JSON_THROW_ON_ERROR)));
        $transport = new SignedSiteTransport($http);
        $installation = $this->installationA();
        $message = $this->message('signed-provider', $this->alias($installation->routeToken));

        $result = $transport->deliver($installation, $message, null);

        $this->assertSame($token, $result->conversationToken);
        $this->assertCount(1, $http->requests);
        $request = $http->requests[0];
        $this->assertSame($installation->webhookUrl, $request['url']);
        $payload = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($installation->routeToken, $payload['route_token']);
        $this->assertNull($payload['conversation_token']);
        $this->assertArrayHasKey('Secretary-Signature', $request['headers']);
        $verificationStore = new MemoryRelayStore([$installation]);
        Signature::verify(
            $installation,
            $verificationStore,
            $request['headers'],
            'POST',
            '/_secretary/webhooks/relay/inbound',
            $request['body'],
        );
        $this->addToAssertionCount(1);
    }

    public function test_a_site_csrf_mismatch_is_retryable_instead_of_silently_discarded(): void
    {
        $http = new MemoryHttpTransport(new HttpTransportResponse(419, json_encode([
            'message' => 'CSRF token mismatch.',
        ], JSON_THROW_ON_ERROR)));
        $transport = new SignedSiteTransport($http);
        $installation = $this->installationA();

        $this->expectException(RelayTransientFailure::class);
        $this->expectExceptionMessage('Site delivery is temporarily unavailable.');

        $transport->deliver(
            $installation,
            $this->message('csrf-provider', $this->alias($installation->routeToken)),
            null,
        );
    }

    public function test_signatures_bind_body_time_installation_and_single_use_nonce(): void
    {
        $installation = $this->installationA();
        $store = new MemoryRelayStore([$installation]);
        $body = '{"version":1}';
        $headers = Signature::headers($installation, 'POST', '/v1/replies', $body, time(), str_repeat('n', 32));

        Signature::verify($installation, $store, $headers, 'POST', '/v1/replies', $body);
        $this->addToAssertionCount(1);

        try {
            Signature::verify($installation, $store, $headers, 'POST', '/v1/replies', $body);
            $this->fail('A replayed nonce was accepted.');
        } catch (RelayRejected $exception) {
            $this->assertSame('Relay nonce has already been used.', $exception->getMessage());
        }

        $fresh = Signature::headers($installation, 'POST', '/v1/replies', $body, time(), str_repeat('m', 32));
        $this->expectException(RelayRejected::class);
        Signature::verify($installation, $store, $fresh, 'POST', '/v1/replies', '{"version":2}');
    }

    public function test_signed_replies_are_bound_to_original_site_route_conversation_and_recipient(): void
    {
        [$store, , $router] = $this->router();
        $routed = $router->route($this->message('reply-inbound', $this->alias($this->installationA()->routeToken)));
        $mail = new MemoryMailTransport;
        $service = new ReplyService($store, $mail, new RelayAddress('secretary@statamic.no'));
        $payload = $this->replyPayload('reply-inbound', $this->installationA(), $routed->conversationToken, [
            'version' => 2,
            'locale' => 'en',
            'body' => 'The draft is ready.',
        ]);
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = Signature::headers($this->installationA(), 'POST', '/v1/replies', $body);

        $first = $service->accept($headers, 'POST', '/v1/replies', $body);
        $duplicate = $service->accept(
            Signature::headers($this->installationA(), 'POST', '/v1/replies', $body),
            'POST',
            '/v1/replies',
            $body,
        );

        $this->assertFalse($first->duplicate);
        $this->assertTrue($duplicate->duplicate);
        $this->assertSame($first->providerMessageId, $duplicate->providerMessageId);
        $this->assertCount(1, $mail->replies);
        $reply = $mail->replies[0];
        $this->assertSame('editor@example.com', $reply->recipient);
        $this->assertSame('en', $reply->locale);
        $this->assertSame(
            $this->alias($this->installationA()->routeToken, $routed->conversationToken),
            $reply->replyTo,
        );
    }

    public function test_reply_idempotency_key_cannot_be_reused_with_changed_content(): void
    {
        [$store, , $router] = $this->router();
        $routed = $router->route($this->message('reply-content-inbound', $this->alias($this->installationA()->routeToken)));
        $mail = new MemoryMailTransport;
        $service = new ReplyService($store, $mail, new RelayAddress('secretary@statamic.no'));
        $payload = $this->replyPayload('reply-content-inbound', $this->installationA(), $routed->conversationToken);
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $service->accept(
            Signature::headers($this->installationA(), 'POST', '/v1/replies', $body),
            'POST',
            '/v1/replies',
            $body,
        );

        $payload['body'] = 'Et annet svar.';
        $changedBody = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        try {
            $service->accept(
                Signature::headers($this->installationA(), 'POST', '/v1/replies', $changedBody),
                'POST',
                '/v1/replies',
                $changedBody,
            );
            $this->fail('A changed reply reused an existing idempotency key.');
        } catch (RelayRejected $exception) {
            $this->assertSame('Reply idempotency key conflicts with an existing claim.', $exception->getMessage());
        }

        $this->assertCount(1, $mail->replies);
    }

    public function test_signed_reply_cannot_substitute_recipient_route_or_inbound_message(): void
    {
        [$store, , $router] = $this->router();
        $a = $router->route($this->message('inbound-a', $this->alias($this->installationA()->routeToken)));
        $b = $router->route($this->message('inbound-b', $this->alias($this->installationB()->routeToken)));
        $service = new ReplyService($store, new MemoryMailTransport, new RelayAddress('secretary@statamic.no'));
        $invalidPayloads = [
            $this->replyPayload('inbound-a', $this->installationA(), $a->conversationToken, ['recipient' => 'attacker@example.com']),
            $this->replyPayload('inbound-a', $this->installationA(), $a->conversationToken, ['route_token' => $this->installationB()->routeToken]),
            $this->replyPayload('inbound-b', $this->installationA(), $a->conversationToken),
            $this->replyPayload('inbound-a', $this->installationA(), $b->conversationToken),
        ];

        foreach ($invalidPayloads as $index => $payload) {
            $payload['idempotency_key'] = 'secretary-reply-'.str_repeat((string) ($index + 1), 24);
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $headers = Signature::headers($this->installationA(), 'POST', '/v1/replies', $body);

            try {
                $service->accept($headers, 'POST', '/v1/replies', $body);
                $this->fail('A cross-boundary reply was accepted.');
            } catch (RelayRejected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_compact_tokens_keep_the_full_reply_alias_within_the_email_limit(): void
    {
        $address = new RelayAddress('secretary@statamic.no');
        $routes = [];
        $conversations = [];

        for ($index = 0; $index < 100; $index++) {
            $route = Tokens::route();
            $conversation = Tokens::conversation();
            $replyTo = $address->replyTo($route, $conversation);
            [$local] = explode('@', $replyTo, 2);
            $this->assertLessThanOrEqual(64, strlen($local));
            $this->assertMatchesRegularExpression('/^r[a-z0-9]{25}$/D', $route);
            $this->assertMatchesRegularExpression('/^c[a-z0-9]{25}$/D', $conversation);
            $routes[] = $route;
            $conversations[] = $conversation;
        }

        $this->assertCount(100, array_unique($routes));
        $this->assertCount(100, array_unique($conversations));
        $this->assertSame(32, strlen(Tokens::signingSecret()));
    }

    public function test_postmark_inbound_adapter_uses_mailbox_hash_and_author_domain_dkim(): void
    {
        $route = Tokens::route();
        $conversation = Tokens::conversation();
        $adapter = new PostmarkInboundAdapter('secretary@statamic.no');

        $message = $adapter->adapt($this->postmarkPayload($route.'.'.$conversation));

        $this->assertSame('postmark-inbound-1', $message->providerMessageId);
        $this->assertSame('secretary+'.$route.'.'.$conversation.'@statamic.no', $message->recipient);
        $this->assertSame('editor@example.com', $message->sender);
        $this->assertSame('Bare dette svaret.', $message->body);
        $this->assertTrue($message->senderAuthenticated);
        $this->assertSame(-0.1, $message->spamScore);
        $this->assertSame('<postmark-inbound-1@example.com>', $message->rfcMessageId);
    }

    public function test_postmark_inbound_adapter_forwards_valid_images_as_version_two(): void
    {
        $adapter = new PostmarkInboundAdapter('secretary@statamic.no');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $message = $adapter->adapt($this->postmarkPayload(Tokens::route(), [
            'Attachments' => [[
                'Name' => 'hero.png',
                'ContentType' => 'image/png',
                'Content' => base64_encode($bytes),
                'ContentLength' => strlen($bytes),
            ]],
        ]));
        $payload = $message->sitePayload(Tokens::route(), null);

        $this->assertSame(2, $payload['version']);
        $this->assertCount(1, $message->attachments);
        $this->assertSame('hero.png', $payload['attachments'][0]['name']);
        $this->assertSame(hash('sha256', $bytes), $payload['attachments'][0]['sha256']);
        $this->assertSame(base64_encode($bytes), $payload['attachments'][0]['content']);
    }

    public function test_postmark_inbound_adapter_recovers_a_forwarded_reply_hash_from_to_full(): void
    {
        $route = Tokens::route();
        $conversation = Tokens::conversation();
        $hash = $route.'.'.$conversation;
        $adapter = new PostmarkInboundAdapter('secretary@statamic.no');

        $message = $adapter->adapt($this->postmarkPayload('', [
            'ToFull' => [[
                'Email' => 'secretary+'.$hash.'@statamic.no',
                'Name' => '',
                'MailboxHash' => $hash,
            ]],
        ]));

        $this->assertSame('secretary+'.$hash.'@statamic.no', $message->recipient);
        $this->assertSame('Bare dette svaret.', $message->body);
    }

    public function test_postmark_inbound_adapter_preserves_one_direct_public_alias(): void
    {
        $adapter = new PostmarkInboundAdapter('secretary@statamic.no');

        $message = $adapter->adapt($this->postmarkPayload('', [
            'ToFull' => [[
                'Email' => 'Site-A.Example.com@statamic.no',
                'Name' => '',
                'MailboxHash' => '',
            ]],
        ]));

        $this->assertSame('site-a.example.com@statamic.no', $message->recipient);
    }

    public function test_postmark_inbound_adapter_rejects_inconsistent_or_ambiguous_to_full_hashes(): void
    {
        $adapter = new PostmarkInboundAdapter('secretary@statamic.no');
        $routeA = Tokens::route();
        $routeB = Tokens::route();
        $conversationA = Tokens::conversation();
        $conversationB = Tokens::conversation();
        $hashA = $routeA.'.'.$conversationA;
        $hashB = $routeB.'.'.$conversationB;
        $invalidPayloads = [
            $this->postmarkPayload('', ['ToFull' => [[
                'Email' => 'secretary+'.$hashA.'@statamic.no',
                'Name' => '',
                'MailboxHash' => $hashB,
            ]]]),
            $this->postmarkPayload('', ['ToFull' => [
                [
                    'Email' => 'secretary+'.$hashA.'@statamic.no',
                    'Name' => '',
                    'MailboxHash' => $hashA,
                ],
                [
                    'Email' => 'secretary+'.$hashB.'@statamic.no',
                    'Name' => '',
                    'MailboxHash' => $hashB,
                ],
            ]]),
            $this->postmarkPayload($hashA, ['ToFull' => [[
                'Email' => 'secretary+'.$hashB.'@statamic.no',
                'Name' => '',
                'MailboxHash' => $hashB,
            ]]]),
            $this->postmarkPayload('', ['ToFull' => [
                [
                    'Email' => 'site-a.example.com@statamic.no',
                    'Name' => '',
                    'MailboxHash' => '',
                ],
                [
                    'Email' => 'site-b.example.com@statamic.no',
                    'Name' => '',
                    'MailboxHash' => '',
                ],
            ]]),
        ];

        foreach ($invalidPayloads as $payload) {
            try {
                $adapter->adapt($payload);
                $this->fail('An inconsistent Postmark recipient hash was accepted.');
            } catch (RelayRejected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_postmark_inbound_adapter_rejects_unsafe_or_unsupported_messages(): void
    {
        $adapter = new PostmarkInboundAdapter('secretary@statamic.no');
        $invalidPayloads = [
            $this->postmarkPayload(Tokens::route(), ['Attachments' => [['Name' => 'file.txt']]]),
            $this->postmarkPayload(Tokens::route(), ['StrippedTextReply' => '', 'TextBody' => '', 'HtmlBody' => '<p>HTML only</p>']),
            $this->postmarkPayload('invalid-route'),
            $this->postmarkPayload(Tokens::route(), ['Headers' => [
                ['Name' => 'X-Spam-Score', 'Value' => '9.0'],
                ['Name' => 'X-Spam-Tests', 'Value' => 'DKIM_VALID_AU'],
            ]]),
            $this->postmarkPayload(Tokens::route(), ['Headers' => [
                ['Name' => 'X-Spam-Score', 'Value' => '-0.1'],
                ['Name' => 'X-Spam-Tests', 'Value' => 'SPF_PASS'],
            ]]),
        ];

        foreach ($invalidPayloads as $payload) {
            try {
                $adapter->adapt($payload);
                $this->fail('An unsafe Postmark inbound payload was accepted.');
            } catch (RelayRejected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_postmark_mail_transport_sends_threaded_plain_text_without_leaking_the_token(): void
    {
        $http = new MemoryHttpTransport(new HttpTransportResponse(200, json_encode([
            'ErrorCode' => 0,
            'Message' => 'OK',
            'MessageID' => 'postmark-outbound-1',
        ], JSON_THROW_ON_ERROR)));
        $token = 'postmark-server-token-do-not-leak';
        $transport = new PostmarkMailTransport(
            $http,
            $token,
            'secretary@statamic.no',
            'Secretary',
        );
        $reply = new OutboundReply(
            'secretary-reply-'.str_repeat('a', 24),
            $this->installationA()->id,
            'editor@example.com',
            'Re: Endre forsiden',
            "Utkastet er klart.\n\nVedlegg i Statamic:\n- hero.png\n  https://site-a.example.com/cp/assets/containers/assets/assets/hero.png",
            $this->alias(Tokens::route(), Tokens::conversation()),
            '<postmark-inbound-1@example.com>',
            'https://site-a.example.com/cp/secretary/thread',
            [[
                'id' => 'draft-1',
                'status' => 'draft',
                'summary' => 'Oppdatert forside',
                'native_url' => 'https://site-a.example.com/cp/collections/pages/entries/home',
                'resource_title' => 'Forsiden',
                'public_url' => 'https://site-a.example.com/',
            ]],
            'nb',
        );

        $providerMessageId = $transport->send($reply);

        $this->assertSame('postmark-outbound-1', $providerMessageId);
        $this->assertCount(1, $http->requests);
        $request = $http->requests[0];
        $this->assertSame('https://api.postmarkapp.com/email', $request['url']);
        $this->assertSame($token, $request['headers']['X-Postmark-Server-Token']);
        $this->assertStringNotContainsString($token, $request['body']);
        $payload = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('secretary@statamic.no', trim(explode('<', $payload['From'], 2)[1], '>'));
        $this->assertSame($reply->replyTo, $payload['ReplyTo']);
        $this->assertSame('In-Reply-To', $payload['Headers'][0]['Name']);
        $this->assertSame($reply->inReplyTo, $payload['Headers'][0]['Value']);
        $this->assertStringContainsString('Berørt side: Forsiden — https://site-a.example.com/', $payload['TextBody']);
        $this->assertStringNotContainsString('Klargjorte endringer', $payload['TextBody']);
        $this->assertStringNotContainsString('Oppdatert forside — utkast', $payload['TextBody']);
        $this->assertStringContainsString('Åpne utkastet i Statamic', $payload['TextBody']);
        $this->assertStringContainsString($reply->changeSets[0]['native_url'], $payload['TextBody']);
        $this->assertStringContainsString('Fortsett samtalen i Secretary', $payload['TextBody']);
        $this->assertStringContainsString($reply->reviewUrl, $payload['TextBody']);
        $this->assertStringContainsString(
            '<a href="https://site-a.example.com/cp/assets/containers/assets/assets/hero.png" style="color:#2563eb;text-decoration:underline">hero.png</a>',
            $payload['HtmlBody'],
        );
        $this->assertStringContainsString(
            '<a href="'.$reply->reviewUrl.'"',
            $payload['HtmlBody'],
        );
    }

    public function test_postmark_mail_transport_links_every_resource_when_one_reply_changes_multiple_items(): void
    {
        $http = new MemoryHttpTransport(new HttpTransportResponse(200, json_encode([
            'ErrorCode' => 0,
            'Message' => 'OK',
            'MessageID' => 'postmark-outbound-many',
        ], JSON_THROW_ON_ERROR)));
        $transport = new PostmarkMailTransport(
            $http,
            'postmark-server-token',
            'secretary@statamic.no',
            'Secretary',
        );
        $reply = new OutboundReply(
            'secretary-reply-'.str_repeat('b', 24),
            $this->installationA()->id,
            'editor@example.com',
            'Re: Update content',
            'Two drafts are ready.',
            $this->alias(Tokens::route(), Tokens::conversation()),
            '<postmark-inbound-many@example.com>',
            'https://site-a.example.com/cp/secretary/thread',
            [[
                'id' => 'draft-entry',
                'status' => 'draft',
                'summary' => 'Updated the About page',
                'native_url' => 'https://site-a.example.com/cp/collections/pages/entries/about?secretary=thread',
                'resource_title' => 'About',
                'public_url' => 'https://site-a.example.com/about',
            ], [
                'id' => 'draft-global',
                'status' => 'draft',
                'summary' => 'Updated the Company globals',
                'native_url' => 'https://site-a.example.com/cp/globals/company',
                'resource_title' => 'Company',
                'public_url' => null,
            ]],
            'en',
        );

        $transport->send($reply);
        $payload = json_decode($http->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR);

        foreach ($reply->changeSets as $changeSet) {
            $this->assertStringContainsString($changeSet['summary'], $payload['TextBody']);
            $this->assertStringContainsString($changeSet['native_url'], $payload['TextBody']);
            $this->assertStringContainsString(
                '<a href="'.$changeSet['native_url'].'"',
                $payload['HtmlBody'],
            );
        }

        $this->assertStringContainsString($reply->reviewUrl, $payload['TextBody']);
    }

    /** @return array{MemoryRelayStore, MemorySiteTransport, InboundRouter} */
    private function router(): array
    {
        $store = new MemoryRelayStore([$this->installationA(), $this->installationB()]);
        $transport = new MemorySiteTransport;
        $router = new InboundRouter($store, $transport, new RelayAddress('secretary@statamic.no'));

        return [$store, $transport, $router];
    }

    private function installationA(bool $active = true, array $senders = ['editor@example.com']): Installation
    {
        return new Installation(
            'si_'.str_repeat('a', 32),
            'r'.str_repeat('a', 25),
            'https://site-a.example.com/_secretary/webhooks/relay/inbound',
            str_repeat('a', 32),
            $senders,
            $active,
            'Site A',
            publicAlias: 'site-a.example.com',
        );
    }

    private function installationB(bool $active = true, array $senders = ['editor@example.com']): Installation
    {
        return new Installation(
            'si_'.str_repeat('b', 32),
            'r'.str_repeat('b', 25),
            'https://site-b.example.com/_secretary/webhooks/relay/inbound',
            str_repeat('b', 32),
            $senders,
            $active,
            'Site B',
            publicAlias: 'site-b.example.com',
        );
    }

    private function message(
        string $providerMessageId,
        string $recipient,
        bool $authenticated = true,
    ): InboundMessage {
        return new InboundMessage(
            $providerMessageId,
            $recipient,
            'editor@example.com',
            'Oppdater forsiden.',
            'Endre forsiden',
            $authenticated,
            -0.1,
            '<'.$providerMessageId.'@example.com>',
        );
    }

    private function alias(string $routeToken, ?string $conversationToken = null): string
    {
        return 'secretary+'.$routeToken.($conversationToken ? '.'.$conversationToken : '').'@statamic.no';
    }

    /** @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function postmarkPayload(string $mailboxHash, array $overrides = []): array
    {
        return [
            'MessageID' => 'postmark-inbound-1',
            'MailboxHash' => $mailboxHash,
            'Subject' => 'Endre forsiden',
            'StrippedTextReply' => 'Bare dette svaret.',
            'TextBody' => 'Hele tråden.',
            'HtmlBody' => '<p>Hele tråden.</p>',
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => [
                ['Name' => 'X-Spam-Score', 'Value' => '-0.1'],
                ['Name' => 'X-Spam-Tests', 'Value' => 'DKIM_SIGNED,DKIM_VALID,DKIM_VALID_AU,SPF_PASS'],
                ['Name' => 'Message-ID', 'Value' => '<postmark-inbound-1@example.com>'],
            ],
            'Attachments' => [],
            ...$overrides,
        ];
    }

    /** @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function replyPayload(
        string $inboundProviderMessageId,
        Installation $installation,
        string $conversationToken,
        array $overrides = [],
    ): array {
        return [
            'version' => 1,
            'idempotency_key' => 'secretary-reply-'.str_repeat('a', 24),
            'inbound_provider_message_id' => $inboundProviderMessageId,
            'recipient' => 'editor@example.com',
            'subject' => 'Re: Endre forsiden',
            'body' => 'Utkastet er klart.',
            'review_url' => 'https://site-a.example.com/cp/secretary/thread',
            'change_sets' => [[
                'id' => '01relaychangeset',
                'status' => 'draft',
                'summary' => 'Oppdatert forside',
                'native_url' => 'https://site-a.example.com/cp/collections/pages/entries/home',
            ]],
            'route_token' => $installation->routeToken,
            'conversation_token' => $conversationToken,
            'in_reply_to' => '<'.$inboundProviderMessageId.'@example.com>',
            ...$overrides,
        ];
    }
}

final class MemoryRelayStore implements RelayStore
{
    /** @var array<string, Installation> */
    private array $installations = [];

    /** @var array<string, ConversationRoute> */
    private array $conversations = [];

    /** @var array<string, array{installation: string, fingerprint: string, status: ClaimState}> */
    private array $inboundClaims = [];

    /** @var array<string, InboundDelivery> */
    private array $inboundDeliveries = [];

    /** @var array<string, int> */
    private array $nonces = [];

    /** @var array<string, array{installation: string, fingerprint: string, status: ClaimState, provider?: string}> */
    private array $replyClaims = [];

    /** @param  array<int, Installation>  $installations */
    public function __construct(array $installations)
    {
        foreach ($installations as $installation) {
            $this->installations[$installation->id] = $installation;
        }
    }

    public function installationById(string $id): ?Installation
    {
        return $this->installations[$id] ?? null;
    }

    public function installationByRouteToken(string $routeToken): ?Installation
    {
        foreach ($this->installations as $installation) {
            if (hash_equals($installation->routeToken, $routeToken)) {
                return $installation;
            }
        }

        return null;
    }

    public function installationByPublicAlias(string $publicAlias): ?Installation
    {
        $publicAlias = mb_strtolower(trim($publicAlias));

        foreach ($this->installations as $installation) {
            if ($installation->publicAlias !== null
                && hash_equals($installation->publicAlias, $publicAlias)) {
                return $installation;
            }
        }

        return null;
    }

    public function installationsForSender(string $sender): array
    {
        return array_values(array_filter(
            $this->installations,
            fn (Installation $installation): bool => $installation->allowsSender($sender),
        ));
    }

    public function conversationByToken(string $token): ?ConversationRoute
    {
        return $this->conversations[$token] ?? null;
    }

    public function saveConversation(ConversationRoute $conversation): void
    {
        $existing = $this->conversations[$conversation->token] ?? null;

        if ($existing && $existing != $conversation) {
            throw new RelayRejected('Conversation token collision.');
        }

        $this->conversations[$conversation->token] = $conversation;
    }

    public function claimInbound(string $providerMessageId, string $installationId, string $fingerprint): ClaimState
    {
        $existing = $this->inboundClaims[$providerMessageId] ?? null;

        if (! $existing) {
            $this->inboundClaims[$providerMessageId] = [
                'installation' => $installationId,
                'fingerprint' => $fingerprint,
                'status' => ClaimState::Processing,
            ];

            return ClaimState::New;
        }

        return hash_equals($existing['installation'], $installationId)
            && hash_equals($existing['fingerprint'], $fingerprint)
                ? $existing['status']
                : ClaimState::Conflict;
    }

    public function completeInbound(InboundDelivery $delivery): void
    {
        $this->inboundDeliveries[$delivery->providerMessageId] = $delivery;
        $this->inboundClaims[$delivery->providerMessageId] = [
            'installation' => $delivery->installationId,
            'fingerprint' => $this->inboundClaims[$delivery->providerMessageId]['fingerprint'],
            'status' => ClaimState::Complete,
        ];
    }

    public function releaseInbound(string $providerMessageId, string $installationId): void
    {
        $claim = $this->inboundClaims[$providerMessageId] ?? null;

        if ($claim && $claim['status'] === ClaimState::Processing && hash_equals($claim['installation'], $installationId)) {
            unset($this->inboundClaims[$providerMessageId]);
        }
    }

    public function inboundDelivery(string $providerMessageId): ?InboundDelivery
    {
        return $this->inboundDeliveries[$providerMessageId] ?? null;
    }

    public function consumeNonce(string $installationId, string $nonce, int $expiresAt): bool
    {
        $key = $installationId."\0".$nonce;

        if (($this->nonces[$key] ?? 0) >= time()) {
            return false;
        }

        $this->nonces[$key] = $expiresAt;

        return true;
    }

    public function claimReply(string $idempotencyKey, string $installationId, string $fingerprint): ClaimState
    {
        $existing = $this->replyClaims[$idempotencyKey] ?? null;

        if (! $existing) {
            $this->replyClaims[$idempotencyKey] = [
                'installation' => $installationId,
                'fingerprint' => $fingerprint,
                'status' => ClaimState::Processing,
            ];

            return ClaimState::New;
        }

        return hash_equals($existing['installation'], $installationId)
            && hash_equals($existing['fingerprint'], $fingerprint)
                ? $existing['status']
                : ClaimState::Conflict;
    }

    public function completeReply(string $idempotencyKey, string $installationId, string $providerMessageId): void
    {
        $this->replyClaims[$idempotencyKey] = [
            'installation' => $installationId,
            'fingerprint' => $this->replyClaims[$idempotencyKey]['fingerprint'],
            'status' => ClaimState::Complete,
            'provider' => $providerMessageId,
        ];
    }

    public function releaseReply(string $idempotencyKey, string $installationId): void
    {
        $claim = $this->replyClaims[$idempotencyKey] ?? null;

        if ($claim && $claim['status'] === ClaimState::Processing && hash_equals($claim['installation'], $installationId)) {
            unset($this->replyClaims[$idempotencyKey]);
        }
    }

    public function completedReplyProviderId(string $idempotencyKey, string $installationId): ?string
    {
        $claim = $this->replyClaims[$idempotencyKey] ?? null;

        return $claim
            && $claim['status'] === ClaimState::Complete
            && hash_equals($claim['installation'], $installationId)
                ? ($claim['provider'] ?? null)
                : null;
    }
}

final class MemorySiteTransport implements SiteTransport
{
    /** @var array<int, array{installation: Installation, message: InboundMessage, conversation: string|null, acknowledgement_sent: bool}> */
    public array $deliveries = [];

    public bool $failNext = false;

    public int $attempts = 0;

    public function deliver(
        Installation $installation,
        InboundMessage $message,
        ?string $conversationToken,
        bool $acknowledgementSent = false,
    ): SiteDeliveryResult {
        $this->attempts++;

        if ($this->failNext) {
            $this->failNext = false;

            throw new RuntimeException('Simulated site failure.');
        }

        $conversationToken ??= 'c'.substr(hash('sha256', $installation->id."\0".$message->providerMessageId), 0, 25);
        $this->deliveries[] = compact('installation', 'message') + [
            'conversation' => $conversationToken,
            'acknowledgement_sent' => $acknowledgementSent,
        ];

        return new SiteDeliveryResult($conversationToken);
    }
}

final class MemoryMailTransport implements MailTransport
{
    /** @var array<int, OutboundReply> */
    public array $replies = [];

    public function send(OutboundReply $reply): string
    {
        $this->replies[] = $reply;

        return 'postmark-reply-'.count($this->replies);
    }
}

final class MemoryHttpTransport implements HttpTransport
{
    /** @var array<int, array{url: string, body: string, headers: array<string, string>}> */
    public array $requests = [];

    public function __construct(private readonly HttpTransportResponse $response) {}

    public function post(string $url, string $body, array $headers): HttpTransportResponse
    {
        $this->requests[] = compact('url', 'body', 'headers');

        return $this->response;
    }
}
