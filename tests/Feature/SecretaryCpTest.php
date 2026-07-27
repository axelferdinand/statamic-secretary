<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Agent\ConversationService;
use AxelFerdinand\StatamicSecretary\Contracts\AgentClient;
use AxelFerdinand\StatamicSecretary\Data\AgentRequest;
use AxelFerdinand\StatamicSecretary\Data\AgentResponse;
use AxelFerdinand\StatamicSecretary\Jobs\ProcessCpMessage;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Statamic\Facades\User;

class SecretaryCpTest extends TestCase
{
    public function test_an_authorized_user_can_open_the_control_panel_assistant(): void
    {
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => ['title' => 'Forsiden'],
        ]);
        $conversation->changeSets()->create([
            'status' => 'draft',
            'operation' => 'update',
            'resource_type' => 'entry',
            'resource_id' => 'home',
            'collection' => 'pages',
            'site' => 'default',
            'patch' => ['title' => 'Etter'],
            'before' => ['data' => ['title' => 'Før']],
            'after' => ['data' => ['title' => 'Etter']],
        ]);

        $this->actingAs($user)
            ->get('/cp/secretary/'.$conversation->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('statamic-secretary::Secretary')
                ->where('conversation.id', $conversation->id)
                ->where('conversation.changes.0.before.data.title', 'Før')
                ->where('conversation.changes.0.after.data.title', 'Etter')
                ->where('can_publish', true)
                ->where('max_input_characters', 20000)
                ->has('conversations', 1));
    }

    public function test_email_conversations_are_visible_and_can_be_reviewed_from_the_control_panel(): void
    {
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => ['title' => 'CP-samtale'],
        ]);
        $email = Conversation::create([
            'channel' => 'email',
            'external_thread_id' => 'postmark-message',
            'user_id' => $user->id(),
            'email' => $user->email(),
            'status' => 'open',
            'context' => ['title' => 'E-postsamtale'],
        ]);
        $email->messages()->create([
            'direction' => 'inbound',
            'channel' => 'email',
            'role' => 'user',
            'body' => 'Oppdater forsiden.',
        ]);

        $this->actingAs($user)
            ->get('/cp/secretary')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('statamic-secretary::Secretary')
                ->where('conversation.id', $email->id)
                ->where('conversation.channel', 'email')
                ->where('conversation.messages.0.channel', 'email')
                ->where('conversations.0.channel', 'email')
                ->has('conversations', 2));
    }

    public function test_the_global_panel_returns_only_the_current_users_conversations(): void
    {
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $other = User::make()->id('other@example.com')->email('other@example.com')->makeSuper();
        $other->save();
        $conversation = Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => ['title' => 'Min samtale'],
        ]);
        $conversation->messages()->create([
            'direction' => 'outbound',
            'channel' => 'cp',
            'role' => 'assistant',
            'body' => 'Utkastet er klart.',
            'processed_at' => now(),
        ]);
        Conversation::create([
            'channel' => 'cp',
            'user_id' => $other->id(),
            'status' => 'open',
            'context' => ['title' => 'Annen brukers samtale'],
        ]);

        $this->actingAs($user)
            ->getJson('/cp/secretary/panel/data?conversation_id='.$conversation->id)
            ->assertOk()
            ->assertJsonPath('conversation.id', $conversation->id)
            ->assertJsonPath('conversation.messages.0.body', 'Utkastet er klart.')
            ->assertJsonPath('conversation.full_url', 'http://localhost/cp/secretary/'.$conversation->id)
            ->assertJsonPath('create_url', 'http://localhost/cp/secretary/panel/conversations')
            ->assertJsonCount(1, 'conversations')
            ->assertJsonMissing(['title' => 'Annen brukers samtale']);
    }

    public function test_the_global_panel_can_create_a_conversation_and_queue_a_message(): void
    {
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        Bus::fake();

        $created = $this->actingAs($user)
            ->postJson('/cp/secretary/panel/conversations')
            ->assertCreated()
            ->assertJsonPath('conversation.channel', 'cp')
            ->assertJsonPath('conversation.processing', false);
        $conversationId = $created->json('conversation.id');

        $this->actingAs($user)
            ->postJson('/cp/secretary/panel/'.$conversationId.'/messages', ['message' => 'Oppdater forsiden.'])
            ->assertStatus(202)
            ->assertJsonPath('conversation.id', $conversationId)
            ->assertJsonPath('conversation.processing', true)
            ->assertJsonPath('conversation.messages.0.body', 'Oppdater forsiden.');

        $message = Conversation::findOrFail($conversationId)->messages()->where('direction', 'inbound')->firstOrFail();
        Bus::assertDispatchedAfterResponse(
            ProcessCpMessage::class,
            fn (ProcessCpMessage $job): bool => $job->messageId === $message->id,
        );
    }

    public function test_conversations_cannot_be_read_or_changed_through_another_users_cp_routes(): void
    {
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $other = User::make()->id('other@example.com')->email('other@example.com')->makeSuper();
        $other->save();
        $conversation = Conversation::create([
            'channel' => 'cp',
            'user_id' => $other->id(),
            'status' => 'open',
            'context' => ['title' => 'Privat samtale'],
        ]);
        $changeSet = $conversation->changeSets()->create([
            'status' => 'draft',
            'operation' => 'update',
            'resource_type' => 'entry',
            'resource_id' => 'home',
            'collection' => 'pages',
            'site' => 'default',
            'patch' => ['title' => 'Etter'],
            'before' => ['data' => ['title' => 'Før']],
            'after' => ['data' => ['title' => 'Etter']],
        ]);
        Bus::fake();

        $this->actingAs($user)
            ->getJson('/cp/secretary/panel/data?conversation_id='.$conversation->id)
            ->assertNotFound();
        $this->actingAs($user)
            ->postJson('/cp/secretary/panel/'.$conversation->id.'/messages', ['message' => 'Forsøk.'])
            ->assertNotFound();
        $this->actingAs($user)
            ->post('/cp/secretary/'.$conversation->id.'/messages', ['message' => 'Forsøk.'])
            ->assertNotFound();
        $this->actingAs($user)
            ->post('/cp/secretary/'.$conversation->id.'/changes/'.$changeSet->id.'/publish')
            ->assertNotFound();

        $this->assertDatabaseCount('secretary_messages', 0);
        $this->assertSame('draft', $changeSet->fresh()->status);
        Bus::assertNothingDispatched();
    }

    public function test_sending_from_the_control_panel_queues_processing_after_the_response(): void
    {
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => ['title' => 'Samtale'],
        ]);
        Bus::fake();

        $this->actingAs($user)
            ->post('/cp/secretary/'.$conversation->id.'/messages', ['message' => 'Oppdater forsiden.'])
            ->assertRedirect('/cp/secretary/'.$conversation->id);

        $message = $conversation->messages()->where('direction', 'inbound')->firstOrFail();
        $this->assertNull($message->processed_at);
        Bus::assertDispatchedAfterResponse(
            ProcessCpMessage::class,
            fn (ProcessCpMessage $job): bool => $job->messageId === $message->id,
        );
    }

    public function test_an_unconfigured_control_panel_request_does_not_create_a_pending_message(): void
    {
        config()->set('secretary.openai.api_key');
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => ['title' => 'Samtale'],
        ]);
        Bus::fake();

        $this->from('/cp/secretary/'.$conversation->id)
            ->actingAs($user)
            ->post('/cp/secretary/'.$conversation->id.'/messages', ['message' => 'Oppdater forsiden.'])
            ->assertRedirect('/cp/secretary/'.$conversation->id)
            ->assertSessionHasErrors('secretary');

        $this->assertDatabaseCount('secretary_messages', 0);
        Bus::assertNotDispatched(ProcessCpMessage::class);
    }

    public function test_a_persistent_queue_receives_the_cp_job_before_the_response(): void
    {
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        config()->set('queue.default', 'database');
        $conversation = Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => ['title' => 'Samtale'],
        ]);
        Bus::fake();

        $this->actingAs($user)
            ->post('/cp/secretary/'.$conversation->id.'/messages', ['message' => 'Oppdater forsiden.'])
            ->assertRedirect('/cp/secretary/'.$conversation->id);

        Bus::assertDispatchedTimes(ProcessCpMessage::class, 1);
        Bus::assertDispatchedAfterResponseTimes(ProcessCpMessage::class, 0);
    }

    public function test_a_persistent_queue_push_failure_ends_the_message_without_exposing_internal_details(): void
    {
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => ['title' => 'Samtale'],
        ]);
        config()->set('queue.default', 'database');
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(ProcessCpMessage::class))
            ->andThrow(new RuntimeException('redis://username:secret@internal.example'));
        $this->app->instance(Dispatcher::class, $bus);
        $publicError = 'Secretary kunne ikke starte behandlingen. Kontroller loggen og prøv igjen.';

        $this->from('/cp/secretary/'.$conversation->id)
            ->actingAs($user)
            ->post('/cp/secretary/'.$conversation->id.'/messages', ['message' => 'Oppdater forsiden.'])
            ->assertRedirect('/cp/secretary/'.$conversation->id)
            ->assertSessionHasErrors(['secretary' => $publicError]);

        $message = $conversation->messages()->where('direction', 'inbound')->firstOrFail();
        $this->assertNotNull($message->processed_at);
        $this->assertSame($publicError, data_get($message->metadata, 'processing_error'));
        $this->assertStringNotContainsString('secret', json_encode($message->metadata));

        $this->actingAs($user)
            ->get('/cp/secretary/'.$conversation->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('conversation.processing', false)
                ->where('conversation.processing_error', $publicError));
    }

    public function test_publishing_is_refused_while_the_conversation_has_a_pending_message(): void
    {
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => ['title' => 'Samtale'],
        ]);
        $changeSet = $conversation->changeSets()->create([
            'status' => 'draft',
            'operation' => 'update',
            'resource_type' => 'entry',
            'resource_id' => 'home',
            'collection' => 'pages',
            'site' => 'default',
            'patch' => ['title' => 'Etter'],
            'before' => ['data' => ['title' => 'Før']],
            'after' => ['data' => ['title' => 'Etter']],
        ]);
        $conversation->messages()->create([
            'direction' => 'inbound',
            'channel' => 'cp',
            'role' => 'user',
            'body' => 'Oppdater forsiden.',
        ]);

        $this->from('/cp/secretary/'.$conversation->id)
            ->actingAs($user)
            ->post('/cp/secretary/'.$conversation->id.'/changes/'.$changeSet->id.'/publish')
            ->assertRedirect('/cp/secretary/'.$conversation->id)
            ->assertSessionHasErrors('secretary');

        $this->assertSame('draft', $changeSet->fresh()->status);
        $this->assertCount(1, $conversation->messages()->get());
    }

    public function test_a_failed_direct_publication_does_not_leave_the_conversation_processing_forever(): void
    {
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => ['title' => 'Samtale'],
        ]);
        $changeSet = $conversation->changeSets()->create([
            'status' => 'draft',
            'operation' => 'update',
            'resource_type' => 'entry',
            'resource_id' => 'missing-entry',
            'collection' => 'pages',
            'site' => 'default',
            'patch' => ['title' => 'Etter'],
            'before' => ['data' => ['title' => 'Før']],
            'after' => ['data' => ['title' => 'Etter']],
        ]);

        $this->from('/cp/secretary/'.$conversation->id)
            ->actingAs($user)
            ->post('/cp/secretary/'.$conversation->id.'/changes/'.$changeSet->id.'/publish')
            ->assertRedirect('/cp/secretary/'.$conversation->id)
            ->assertSessionHasErrors('secretary');

        $inbound = $conversation->messages()->where('direction', 'inbound')->firstOrFail();
        $this->assertNotNull($inbound->processed_at);
        $this->assertNotEmpty(data_get($inbound->metadata, 'processing_error'));

        $this->actingAs($user)
            ->get('/cp/secretary/'.$conversation->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('conversation.processing', false)
                ->where('conversation.processing_error', data_get($inbound->metadata, 'processing_error')));
    }

    public function test_the_cp_job_reuses_the_stored_reply_when_retried(): void
    {
        $calls = new class
        {
            public int $count = 0;
        };
        $this->app->bind(AgentClient::class, fn () => new class($calls) implements AgentClient
        {
            public function __construct(private readonly object $calls) {}

            public function respond(AgentRequest $request): AgentResponse
            {
                $this->calls->count++;

                return new AgentResponse('resp_cp', 'completed', [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'Utkastet er klart.']],
                ]], 'Utkastet er klart.');
            }
        });
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $service = app(ConversationService::class);
        $conversation = $service->start('cp', $user);
        $message = $service->recordInbound($conversation, 'Oppdater forsiden.', $user, 'cp');
        $job = new ProcessCpMessage($message->id);

        $job->handle($service);
        $job->handle($service);

        $this->assertSame(1, $calls->count);
        $this->assertNotNull($message->fresh()->processed_at);
        $this->assertSame($message->id, $conversation->messages()->where('direction', 'outbound')->first()->reply_to_message_id);
        $this->assertCount(1, $conversation->messages()->where('direction', 'outbound')->get());
    }

    public function test_a_sync_cp_job_contains_after_response_failures_and_marks_the_message(): void
    {
        $this->app->bind(AgentClient::class, fn () => new class implements AgentClient
        {
            public function respond(AgentRequest $request): AgentResponse
            {
                throw new RuntimeException('Internal model failure with secret details.');
            }
        });
        config()->set('queue.default', 'sync');
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $service = app(ConversationService::class);
        $conversation = $service->start('cp', $user);
        $message = $service->recordInbound($conversation, 'Oppdater forsiden.', $user, 'cp');

        (new ProcessCpMessage($message->id))->handle($service);

        $message->refresh();
        $this->assertNotNull($message->processed_at);
        $this->assertSame(
            'Secretary kunne ikke behandle meldingen. Kontroller loggen og prøv igjen.',
            data_get($message->metadata, 'processing_error'),
        );
        $this->assertStringNotContainsString('secret details', json_encode($message->metadata));
    }

    public function test_a_persistent_cp_job_rethrows_failures_for_queue_retries(): void
    {
        $this->app->bind(AgentClient::class, fn () => new class implements AgentClient
        {
            public function respond(AgentRequest $request): AgentResponse
            {
                throw new RuntimeException('Retry this model failure.');
            }
        });
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $service = app(ConversationService::class);
        $conversation = $service->start('cp', $user);
        $message = $service->recordInbound($conversation, 'Oppdater forsiden.', $user, 'cp');
        config()->set('queue.default', 'database');

        try {
            (new ProcessCpMessage($message->id))->handle($service);
            $this->fail('The persistent queue failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Retry this model failure.', $exception->getMessage());
        }

        $this->assertNull($message->fresh()->processed_at);
        $this->assertNull(data_get($message->fresh()->metadata, 'processing_error'));
    }

    public function test_a_newer_cp_message_waits_for_an_older_pending_message_in_the_same_conversation(): void
    {
        $calls = new class
        {
            public int $count = 0;
        };
        $this->app->bind(AgentClient::class, fn () => new class($calls) implements AgentClient
        {
            public function __construct(private readonly object $calls) {}

            public function respond(AgentRequest $request): AgentResponse
            {
                $this->calls->count++;
                $text = 'Svar '.$this->calls->count;

                return new AgentResponse('resp_'.$this->calls->count, 'completed', [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => $text]],
                ]], $text);
            }
        });
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $service = app(ConversationService::class);
        $conversation = $service->start('cp', $user);
        $first = $service->recordInbound($conversation, 'Første melding.', $user, 'cp');
        $second = $service->recordInbound($conversation, 'Andre melding.', $user, 'cp');

        (new ProcessCpMessage($second->id))->handle($service);
        $this->assertSame(0, $calls->count);
        $this->assertNull($second->fresh()->processed_at);

        (new ProcessCpMessage($first->id))->handle($service);
        (new ProcessCpMessage($second->id))->handle($service);

        $this->assertSame(2, $calls->count);
        $this->assertNotNull($first->fresh()->processed_at);
        $this->assertNotNull($second->fresh()->processed_at);
        $this->assertSame(['Svar 1', 'Svar 2'], $conversation->messages()->where('direction', 'outbound')->pluck('body')->all());
    }
}
