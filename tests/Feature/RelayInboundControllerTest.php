<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Agent\ConversationService;
use AxelFerdinand\StatamicSecretary\Agent\PublicationIntentDetector;
use AxelFerdinand\StatamicSecretary\Contracts\AgentClient;
use AxelFerdinand\StatamicSecretary\Data\AgentRequest;
use AxelFerdinand\StatamicSecretary\Data\AgentResponse;
use AxelFerdinand\StatamicSecretary\Exceptions\RelayDeliveryFailed;
use AxelFerdinand\StatamicSecretary\Jobs\ProcessInboundEmail;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Models\Message;
use AxelFerdinand\StatamicSecretary\Relay\RelayClient;
use AxelFerdinand\StatamicSecretary\Relay\RelaySignature;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation as HostedRelayInstallation;
use AxelFerdinand\StatamicSecretaryRelay\Security\Signature as HostedRelaySignature;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Statamic\Facades\User;

class RelayInboundControllerTest extends TestCase
{
    private const PATH = '/_secretary/webhooks/relay/inbound';

    public function test_the_relay_route_is_disabled_by_default(): void
    {
        $this->postJson(self::PATH, [])->assertForbidden();
    }

    public function test_the_relay_route_excludes_csrf_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('secretary.web.relay.inbound');

        $this->assertNotNull($route);
        $this->assertContains(PreventRequestForgery::class, $route->excludedMiddleware());
    }

    public function test_a_signed_relay_message_is_bound_to_this_installation_and_route(): void
    {
        $this->configureRelay();
        $this->authorizedUser();
        Bus::fake();
        $payload = $this->payload('relay-message-1');

        $response = $this->postSigned($payload);

        $response->assertOk()->assertJson(['accepted' => true, 'duplicate' => false]);
        $conversation = Conversation::query()->firstOrFail();
        $message = Message::query()->firstOrFail();
        $this->assertSame('relay', data_get($conversation->context, 'email_delivery'));
        $this->assertSame($this->routeToken(), data_get($conversation->context, 'relay_route_token'));
        $this->assertMatchesRegularExpression('/^c[a-z0-9]{25}$/D', data_get($conversation->context, 'relay_conversation_token'));
        $this->assertSame('relay', data_get($message->metadata, 'email_delivery'));
        $this->assertSame('editor@example.com', $conversation->email);
        Bus::assertDispatchedAfterResponse(
            ProcessInboundEmail::class,
            fn (ProcessInboundEmail $job): bool => $job->messageId === $message->id,
        );
    }

    public function test_the_hosted_relay_signature_contract_is_accepted_by_the_addon(): void
    {
        $this->configureRelay();
        $this->authorizedUser();
        Bus::fake();
        $payload = $this->payload('hosted-contract-message');
        $body = $this->encodePayload($payload);
        $installation = new HostedRelayInstallation(
            (string) config('secretary.relay.installation_id'),
            $this->routeToken(),
            'https://site.example.com/_secretary/webhooks/relay/inbound',
            str_repeat('s', 32),
            ['editor@example.com'],
        );
        $headers = HostedRelaySignature::headers($installation, 'POST', self::PATH, $body);

        $this->callSigned($body, $headers)
            ->assertOk()
            ->assertJson(['accepted' => true, 'duplicate' => false]);

        $this->assertDatabaseHas('secretary_messages', [
            'provider_message_id' => 'hosted-contract-message',
        ]);
    }

    public function test_a_signed_relay_follow_up_reuses_only_its_route_bound_conversation(): void
    {
        $this->configureRelay();
        $this->authorizedUser();
        Bus::fake();
        $first = $this->postSigned($this->payload('relay-thread-1'));
        $token = $first->json('conversation_token');
        $followUp = $this->payload('relay-thread-2', ['conversation_token' => $token, 'body' => 'Andre melding.']);

        $this->postSigned($followUp)->assertOk()->assertJson(['duplicate' => false]);

        $this->assertDatabaseCount('secretary_conversations', 1);
        $this->assertDatabaseCount('secretary_messages', 2);
        $this->assertSame($token, data_get(Conversation::query()->firstOrFail()->context, 'relay_conversation_token'));
    }

    public function test_a_conversation_token_cannot_be_substituted_onto_another_route(): void
    {
        $this->configureRelay();
        $this->authorizedUser();
        Bus::fake();
        $first = $this->postSigned($this->payload('relay-route-a'));
        $token = $first->json('conversation_token');
        $routeB = 'r'.str_repeat('b', 25);
        config()->set('secretary.relay.route_token', $routeB);
        $substitution = $this->payload('relay-route-b', [
            'route_token' => $routeB,
            'conversation_token' => $token,
        ]);

        $this->postSigned($substitution)->assertForbidden();

        $this->assertDatabaseCount('secretary_conversations', 1);
        $this->assertDatabaseCount('secretary_messages', 1);
    }

    public function test_a_valid_signature_for_another_installation_is_rejected(): void
    {
        $this->configureRelay();
        $this->authorizedUser();
        Bus::fake();
        $payload = $this->payload('wrong-installation');
        $body = $this->encodePayload($payload);
        $headers = app(RelaySignature::class)->headers('POST', self::PATH, $body);
        config()->set('secretary.relay.installation_id', 'si_'.str_repeat('b', 32));

        $this->callSigned($body, $headers)->assertForbidden();

        $this->assertDatabaseCount('secretary_conversations', 0);
        $this->assertDatabaseCount('secretary_messages', 0);
    }

    public function test_a_relay_signature_is_bound_to_the_exact_body(): void
    {
        $this->configureRelay();
        $this->authorizedUser();
        Bus::fake();
        $body = $this->encodePayload($this->payload('original-body'));
        $headers = app(RelaySignature::class)->headers('POST', self::PATH, $body);
        $modified = $this->encodePayload($this->payload('modified-body'));

        $this->callSigned($modified, $headers)->assertForbidden();

        $this->assertDatabaseCount('secretary_conversations', 0);
    }

    public function test_a_signature_created_with_another_installations_secret_is_rejected(): void
    {
        $this->configureRelay();
        $this->authorizedUser();
        Bus::fake();
        $body = $this->encodePayload($this->payload('wrong-secret'));
        $headers = app(RelaySignature::class)->headers('POST', self::PATH, $body);
        config()->set('secretary.relay.signing_secret', rtrim(strtr(base64_encode(str_repeat('x', 32)), '+/', '-_'), '='));

        $this->callSigned($body, $headers)->assertForbidden();

        $this->assertDatabaseCount('secretary_conversations', 0);
    }

    public function test_signed_mail_still_requires_sender_authentication_and_a_native_user(): void
    {
        $this->configureRelay();
        Bus::fake();

        $this->postSigned($this->payload('unauthenticated-sender', ['sender_authenticated' => false]))
            ->assertForbidden();
        $this->postSigned($this->payload('unknown-sender', [
            'sender' => 'unknown@example.com',
            'rfc_message_id' => '<unknown-sender@example.com>',
        ]))->assertForbidden();

        $this->assertDatabaseCount('secretary_conversations', 0);
        $this->assertDatabaseCount('secretary_messages', 0);
    }

    public function test_a_relay_nonce_is_single_use(): void
    {
        $this->configureRelay();
        $this->authorizedUser();
        Bus::fake();
        $body = $this->encodePayload($this->payload('replayed-message'));
        $headers = app(RelaySignature::class)->headers('POST', self::PATH, $body, nonce: str_repeat('n', 32));

        $this->callSigned($body, $headers)->assertOk();
        $this->callSigned($body, $headers)->assertStatus(409);

        $this->assertDatabaseCount('secretary_conversations', 1);
        $this->assertDatabaseCount('secretary_messages', 1);
    }

    public function test_a_provider_duplicate_with_a_fresh_nonce_is_idempotent(): void
    {
        $this->configureRelay();
        $this->authorizedUser();
        Bus::fake();
        $payload = $this->payload('provider-duplicate');

        $this->postSigned($payload)->assertOk()->assertJson(['duplicate' => false]);
        $this->postSigned($payload)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertDatabaseCount('secretary_conversations', 1);
        $this->assertDatabaseCount('secretary_messages', 1);
        Bus::assertDispatchedAfterResponseTimes(ProcessInboundEmail::class, 2);
    }

    public function test_a_relay_conversation_sends_one_signed_idempotent_reply_through_the_relay(): void
    {
        $this->configureRelay();
        $this->authorizedUser();
        Bus::fake();
        Mail::fake();
        Http::fake([
            'https://secretary.statamic.no/v1/replies' => Http::response(['accepted' => true], 202),
        ]);
        $this->app->bind(AgentClient::class, fn () => new class implements AgentClient
        {
            public function respond(AgentRequest $request): AgentResponse
            {
                return new AgentResponse('relay-response', 'completed', [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'Utkastet er klart.']],
                ]], 'Utkastet er klart.');
            }
        });
        $this->postSigned($this->payload('relay-reply-message'))->assertOk();
        $message = Message::query()->firstOrFail();
        $job = new ProcessInboundEmail($message->id);

        $job->handle(app(ConversationService::class), app(PublicationIntentDetector::class));
        $job->handle(app(ConversationService::class), app(PublicationIntentDetector::class));

        $reply = $message->conversation->messages()->where('direction', 'outbound')->firstOrFail();
        $this->assertNotNull(data_get($reply->metadata, 'email_sent_at'));
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($message, $reply): bool {
            $payload = $request->data();

            return $request->url() === 'https://secretary.statamic.no/v1/replies'
                && $request->hasHeader('Secretary-Installation', config('secretary.relay.installation_id'))
                && $request->hasHeader('Secretary-Signature')
                && $payload['idempotency_key'] === 'secretary-reply-'.$reply->id
                && $payload['inbound_provider_message_id'] === $message->provider_message_id
                && $payload['route_token'] === $this->routeToken()
                && $payload['conversation_token'] === data_get($message->conversation->context, 'relay_conversation_token')
                && $payload['recipient'] === 'editor@example.com'
                && $payload['body'] === 'Utkastet er klart.';
        });
        Mail::assertNothingSent();
    }

    public function test_the_reply_client_refuses_a_reply_from_another_conversation(): void
    {
        $this->configureRelay();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $service = app(ConversationService::class);
        $first = $service->start('email', $user, $user->email(), 'first', [
            'email_delivery' => 'relay',
            'relay_route_token' => $this->routeToken(),
            'relay_conversation_token' => 'c'.str_repeat('a', 25),
        ]);
        $second = $service->start('email', $user, $user->email(), 'second', [
            'email_delivery' => 'relay',
            'relay_route_token' => $this->routeToken(),
            'relay_conversation_token' => 'c'.str_repeat('b', 25),
        ]);
        $firstInbound = $first->messages()->create([
            'direction' => 'inbound',
            'channel' => 'email',
            'role' => 'user',
            'body' => 'Første.',
            'provider_message_id' => 'first-inbound',
        ]);
        $secondInbound = $second->messages()->create([
            'direction' => 'inbound',
            'channel' => 'email',
            'role' => 'user',
            'body' => 'Andre.',
            'provider_message_id' => 'second-inbound',
        ]);
        $secondReply = $second->messages()->create([
            'direction' => 'outbound',
            'channel' => 'email',
            'role' => 'assistant',
            'body' => 'Svar til andre.',
            'reply_to_message_id' => $secondInbound->id,
        ]);
        Http::fake();

        try {
            app(RelayClient::class)->sendReply($firstInbound, $secondReply);
            $this->fail('A cross-conversation relay reply was accepted.');
        } catch (RelayDeliveryFailed $exception) {
            $this->assertSame('Secretary relay reply configuration is invalid.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_an_expired_relay_request_is_rejected_before_storage(): void
    {
        $this->configureRelay();
        $this->authorizedUser();
        Bus::fake();
        $body = $this->encodePayload($this->payload('expired-message'));
        $headers = app(RelaySignature::class)->headers(
            'POST',
            self::PATH,
            $body,
            timestamp: now()->subMinutes(10)->getTimestamp(),
        );

        $this->callSigned($body, $headers)->assertForbidden();

        $this->assertDatabaseCount('secretary_conversations', 0);
    }

    public function test_a_signed_payload_with_the_wrong_route_or_extra_fields_is_rejected(): void
    {
        $this->configureRelay();
        $this->authorizedUser();
        Bus::fake();

        $this->postSigned($this->payload('wrong-route', ['route_token' => 'r'.str_repeat('z', 25)]))
            ->assertForbidden();
        $this->postSigned($this->payload('extra-field', ['html' => '<strong>Not accepted</strong>']))
            ->assertForbidden();

        $this->assertDatabaseCount('secretary_conversations', 0);
    }

    private function configureRelay(): void
    {
        config()->set('cache.default', 'array');
        Cache::flush();
        config()->set('secretary.relay.enabled', true);
        config()->set('secretary.relay.installation_id', 'si_'.str_repeat('a', 32));
        config()->set('secretary.relay.route_token', $this->routeToken());
        config()->set('secretary.relay.signing_secret', rtrim(strtr(base64_encode(str_repeat('s', 32)), '+/', '-_'), '='));
        config()->set('secretary.relay.max_clock_skew', 300);
        config()->set('secretary.relay.cache_store', 'array');
    }

    private function authorizedUser(): void
    {
        User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper()->save();
    }

    /** @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(string $messageId, array $overrides = []): array
    {
        return [
            'version' => 1,
            'provider_message_id' => $messageId,
            'sender' => 'editor@example.com',
            'subject' => 'Endre forsiden',
            'body' => 'Oppdater forsiden.',
            'sender_authenticated' => true,
            'spam_score' => -0.1,
            'route_token' => $this->routeToken(),
            'conversation_token' => null,
            'rfc_message_id' => '<'.$messageId.'@example.com>',
            ...$overrides,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function postSigned(array $payload): TestResponse
    {
        $body = $this->encodePayload($payload);
        $headers = app(RelaySignature::class)->headers('POST', self::PATH, $body);

        return $this->callSigned($body, $headers);
    }

    /** @param  array<string, string>  $headers */
    private function callSigned(string $body, array $headers): TestResponse
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', mb_strtoupper($name))] = $value;
        }

        return $this->call('POST', self::PATH, [], [], [], $server, $body);
    }

    /** @param  array<string, mixed>  $payload */
    private function encodePayload(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function routeToken(): string
    {
        return 'r'.str_repeat('a', 25);
    }
}
