<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Agent\AgentOrchestrator;
use AxelFerdinand\StatamicSecretary\Agent\ConversationService;
use AxelFerdinand\StatamicSecretary\Contracts\AgentClient;
use AxelFerdinand\StatamicSecretary\Contracts\SecretaryTool;
use AxelFerdinand\StatamicSecretary\Data\AgentRequest;
use AxelFerdinand\StatamicSecretary\Data\AgentResponse;
use AxelFerdinand\StatamicSecretary\Developer\SecretaryToolContext;
use AxelFerdinand\StatamicSecretary\Developer\ToolRegistry;
use AxelFerdinand\StatamicSecretary\Events\MessageReceived;
use AxelFerdinand\StatamicSecretary\Jobs\DeliverSecretaryWebhook;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\User;

class DeveloperExperienceTest extends TestCase
{
    public function test_custom_read_only_tools_are_exposed_and_traced_without_reasoning_content(): void
    {
        config()->set('secretary.developer.mode', true);
        app(ToolRegistry::class)->register(new class implements SecretaryTool
        {
            public function name(): string
            {
                return 'read_campaign_context';
            }

            public function description(): string
            {
                return 'Read the current campaign label.';
            }

            public function parameters(): array
            {
                return ['campaign' => ['type' => 'string']];
            }

            public function required(): array
            {
                return ['campaign'];
            }

            public function execute(SecretaryToolContext $context): array
            {
                return ['ok' => true, 'label' => 'Summer'];
            }
        });
        $client = new class implements AgentClient
        {
            public array $requests = [];

            public function respond(AgentRequest $request): AgentResponse
            {
                $this->requests[] = $request;

                if (count($this->requests) === 1) {
                    return new AgentResponse('resp_tool', 'completed', [[
                        'type' => 'function_call',
                        'call_id' => 'call_campaign',
                        'name' => 'read_campaign_context',
                        'arguments' => '{"campaign":"summer"}',
                    ]], '', ['input_tokens' => 10, 'output_tokens' => 2]);
                }

                return new AgentResponse('resp_final', 'completed', [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'Kampanjen er lest.']],
                ]], 'Kampanjen er lest.', ['input_tokens' => 8, 'output_tokens' => 4]);
            }
        };
        $this->app->instance(AgentClient::class, $client);
        $user = User::make()->id('admin@example.com')->email('admin@example.com')->makeSuper();
        $user->save();
        $conversation = app(ConversationService::class)->start('cp', $user);
        $message = app(ConversationService::class)->recordInbound($conversation, 'Les kampanjen.', $user, 'cp');
        $reply = app(AgentOrchestrator::class)->respond($conversation, $message, $user);

        $this->assertContains('read_campaign_context', array_column($client->requests[0]->tools, 'name'));
        $this->assertSame(18, data_get($reply->metadata, 'developer_trace.usage.input_tokens'));
        $this->assertSame('read_campaign_context', data_get($reply->metadata, 'developer_trace.tools.0.name'));
        $this->assertArrayNotHasKey('reasoning', (array) data_get($reply->metadata, 'developer_trace'));
    }

    public function test_dry_run_command_uses_a_real_user_and_never_creates_content(): void
    {
        $this->app->bind(AgentClient::class, fn () => new class implements AgentClient
        {
            public function respond(AgentRequest $request): AgentResponse
            {
                return new AgentResponse('resp_dry', 'completed', [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'Dry-run ferdig.']],
                ]], 'Dry-run ferdig.', ['input_tokens' => 3, 'output_tokens' => 2]);
            }
        });
        $user = User::make()->id('admin@example.com')->email('admin@example.com')->makeSuper();
        $user->save();

        $this->artisan('secretary:dry-run', [
            'instruction' => 'Kontroller forsiden.',
            '--user' => $user->email(),
            '--json' => true,
        ])
            ->expectsOutputToContain('"dry_run":true')
            ->assertSuccessful();

        $conversation = Conversation::query()->where('channel', 'cli')->firstOrFail();
        $this->assertTrue((bool) data_get($conversation->context, 'dry_run'));
        $this->assertDatabaseCount('secretary_change_sets', 0);
    }

    public function test_message_received_is_a_public_laravel_event(): void
    {
        Event::fake([MessageReceived::class]);
        $user = User::make()->id('admin@example.com')->email('admin@example.com')->makeSuper();
        $user->save();
        $conversation = app(ConversationService::class)->start('cp', $user);
        $message = app(ConversationService::class)->recordInbound($conversation, 'Hei.', $user, 'cp');

        Event::assertDispatched(
            MessageReceived::class,
            fn (MessageReceived $event): bool => $event->message->is($message)
                && $event->payload()['conversation_id'] === $conversation->id,
        );
    }

    public function test_webhook_delivery_signs_the_exact_json_body(): void
    {
        Http::fake(['https://hooks.example.com/*' => Http::response([], 202)]);
        config()->set('secretary.developer.webhooks.url', 'https://hooks.example.com/secretary');
        config()->set('secretary.developer.webhooks.secret', str_repeat('s', 32));

        (new DeliverSecretaryWebhook('change.published', [
            'change_set_id' => '01TEST',
        ]))->handle();

        Http::assertSent(function (Request $request): bool {
            $signature = $request->header('X-Secretary-Signature')[0] ?? '';
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), str_repeat('s', 32));

            return $request->url() === 'https://hooks.example.com/secretary'
                && $request->header('X-Secretary-Event')[0] === 'change.published'
                && hash_equals($expected, $signature);
        });
    }
}
