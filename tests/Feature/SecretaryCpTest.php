<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Agent\ConversationService;
use AxelFerdinand\StatamicSecretary\Content\EntryChangeService;
use AxelFerdinand\StatamicSecretary\Content\EntrySnapshotter;
use AxelFerdinand\StatamicSecretary\Contracts\AgentClient;
use AxelFerdinand\StatamicSecretary\Data\AgentRequest;
use AxelFerdinand\StatamicSecretary\Data\AgentResponse;
use AxelFerdinand\StatamicSecretary\Editorial\EditorialStyleGuide;
use AxelFerdinand\StatamicSecretary\Jobs\ProcessCpMessage;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
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
                ->where('conversation.messages.0.queue_position', 1)
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
            ->assertJsonPath('create_url', 'http://localhost/cp/secretary/panel/conversations')
            ->assertJsonMissingPath('conversation.full_url')
            ->assertJsonMissingPath('home_url')
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

    public function test_a_panel_conversation_can_be_started_in_the_context_of_the_current_entry(): void
    {
        $collection = Collection::make('pages')->title('Pages')->revisionsEnabled(true);
        $collection->save();
        Entry::make()
            ->id('home')
            ->collection($collection)
            ->slug('home')
            ->published(true)
            ->data(['title' => 'Forsiden'])
            ->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();

        $created = $this->actingAs($user)
            ->postJson('/cp/secretary/panel/conversations', [
                'context_url' => 'http://localhost/cp/collections/pages/entries/home',
            ])
            ->assertCreated()
            ->assertJsonPath('conversation.context.resource_type', 'entry')
            ->assertJsonPath('conversation.context.resource_id', 'home')
            ->assertJsonPath('conversation.context.collection', 'pages')
            ->assertJsonPath('conversation.context.title', 'Forsiden');

        $conversation = Conversation::findOrFail($created->json('conversation.id'));

        $this->assertSame('home', data_get($conversation->context, 'cp_context.resource_id'));
        $this->assertSame('pages', data_get($conversation->context, 'cp_context.collection'));
    }

    public function test_the_panel_follows_the_current_entry_and_reports_jobs_running_elsewhere(): void
    {
        $collection = Collection::make('pages')->title('Pages')->revisionsEnabled(true);
        $collection->save();
        Entry::make()
            ->id('home')
            ->collection($collection)
            ->slug('home')
            ->published(true)
            ->data(['title' => 'Forsiden'])
            ->save();
        Entry::make()
            ->id('about')
            ->collection($collection)
            ->slug('om-oss')
            ->published(true)
            ->data(['title' => 'Om oss'])
            ->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $home = Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => [
                'title' => 'Forsidejobb',
                'cp_context' => [
                    'resource_type' => 'entry',
                    'resource_id' => 'home',
                    'collection' => 'pages',
                    'site' => 'default',
                    'title' => 'Forsiden',
                    'edit_url' => 'http://localhost/cp/collections/pages/entries/home',
                ],
            ],
        ]);
        $home->messages()->create([
            'direction' => 'inbound',
            'channel' => 'cp',
            'role' => 'user',
            'body' => 'Oppdater forsiden.',
        ]);
        $about = Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => [
                'title' => 'Om oss-jobb',
                'cp_context' => [
                    'resource_type' => 'entry',
                    'resource_id' => 'about',
                    'collection' => 'pages',
                    'site' => 'default',
                    'title' => 'Om oss',
                    'edit_url' => 'http://localhost/cp/collections/pages/entries/about',
                ],
            ],
        ]);
        $about->messages()->create([
            'direction' => 'outbound',
            'channel' => 'cp',
            'role' => 'assistant',
            'body' => 'Om oss-utkastet er klart.',
            'processed_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/cp/secretary/panel/data?context_url='.urlencode('http://localhost/cp/collections/pages/entries/about'))
            ->assertOk()
            ->assertJsonPath('active_context.resource_id', 'about')
            ->assertJsonPath('active_context_key', 'entry:default:about')
            ->assertJsonPath('conversation.id', $about->id)
            ->assertJsonPath('conversation.messages.0.body', 'Om oss-utkastet er klart.')
            ->assertJsonPath('background_jobs.0.id', $home->id)
            ->assertJsonPath('background_jobs.0.title', 'Forsiden');

        $this->actingAs($user)
            ->getJson('/cp/secretary/panel/data?context_url='.urlencode('http://localhost/cp/collections/pages/entries/home'))
            ->assertOk()
            ->assertJsonPath('conversation.id', $home->id)
            ->assertJsonPath('conversation.processing', true)
            ->assertJsonPath('conversation.messages.0.queue_position', 1)
            ->assertJsonCount(0, 'background_jobs');
    }

    public function test_a_page_without_a_conversation_still_returns_its_active_context(): void
    {
        $collection = Collection::make('pages')->title('Pages');
        $collection->save();
        Entry::make()
            ->id('contact')
            ->collection($collection)
            ->slug('kontakt')
            ->data(['title' => 'Kontakt'])
            ->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();

        $this->actingAs($user)
            ->getJson('/cp/secretary/panel/data?context_url='.urlencode('http://localhost/cp/collections/pages/entries/contact'))
            ->assertOk()
            ->assertJsonPath('active_context.resource_id', 'contact')
            ->assertJsonPath('active_context.title', 'Kontakt')
            ->assertJsonPath('conversation', null);
    }

    public function test_a_page_can_restore_an_older_conversation_from_its_change_set(): void
    {
        $collection = Collection::make('pages')->title('Pages');
        $collection->save();
        Entry::make()
            ->id('legacy-page')
            ->collection($collection)
            ->slug('legacy')
            ->data(['title' => 'Eldre side'])
            ->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create([
            'channel' => 'email',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => ['title' => 'Oppdater den eldre siden'],
        ]);
        $conversation->messages()->create([
            'direction' => 'inbound',
            'channel' => 'email',
            'role' => 'user',
            'body' => 'Oppdater den eldre siden.',
            'processed_at' => now(),
        ]);
        $changeSet = $conversation->changeSets()->create([
            'status' => 'draft',
            'operation' => 'update',
            'resource_type' => 'entry',
            'resource_id' => 'legacy-page',
            'collection' => 'pages',
            'site' => 'default',
            'patch' => ['title' => 'Ny tittel'],
        ]);
        Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => [
                'title' => 'Nyere samtale uten utkast',
                'cp_context' => [
                    'resource_type' => 'entry',
                    'resource_id' => 'legacy-page',
                    'collection' => 'pages',
                    'site' => 'default',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->getJson('/cp/secretary/panel/data?context_url='.urlencode('http://localhost/cp/collections/pages/entries/legacy-page'))
            ->assertOk()
            ->assertJsonPath('conversation.id', $conversation->id)
            ->assertJsonPath('conversation.has_email_messages', true)
            ->assertJsonPath('conversation.has_cp_messages', false)
            ->assertJsonPath('conversation.changes.0.native_url', 'http://localhost/cp/collections/pages/entries/legacy-page?secretary='.$conversation->id)
            ->assertJsonPath('auto_open', true)
            ->assertJsonPath('active_context.resource_id', 'legacy-page');

        Bus::fake();

        $this->actingAs($user)
            ->postJson('/cp/secretary/panel/'.$conversation->id.'/messages', [
                'message' => 'Gjør tittelen litt kortere.',
                'context_url' => 'http://localhost/cp/collections/pages/entries/legacy-page',
            ])
            ->assertStatus(202)
            ->assertJsonPath('conversation.id', $conversation->id)
            ->assertJsonPath('conversation.context.resource_id', 'legacy-page')
            ->assertJsonPath('conversation.has_email_messages', true)
            ->assertJsonPath('conversation.has_cp_messages', true)
            ->assertJsonPath('conversation.messages.1.channel', 'cp')
            ->assertJsonPath('auto_open', true);

        $this->assertSame(
            'legacy-page',
            data_get($conversation->fresh()->context, 'cp_context.resource_id'),
        );
        Bus::assertDispatchedAfterResponse(ProcessCpMessage::class);
    }

    public function test_a_stale_panel_routes_a_message_to_the_conversation_for_the_visible_entry(): void
    {
        $collection = Collection::make('pages')->title('Pages');
        $collection->save();
        Entry::make()
            ->id('home')
            ->collection($collection)
            ->slug('home')
            ->data(['title' => 'Forsiden'])
            ->save();
        Entry::make()
            ->id('about')
            ->collection($collection)
            ->slug('om-oss')
            ->data(['title' => 'Om oss'])
            ->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $home = Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => [
                'title' => 'Forsiden',
                'cp_context' => [
                    'resource_type' => 'entry',
                    'resource_id' => 'home',
                    'collection' => 'pages',
                    'site' => 'default',
                ],
            ],
        ]);
        Bus::fake();

        $response = $this->actingAs($user)
            ->postJson('/cp/secretary/panel/'.$home->id.'/messages', [
                'message' => 'Gjør Om oss kortere.',
                'context_url' => 'http://localhost/cp/collections/pages/entries/about',
            ])
            ->assertStatus(202)
            ->assertJsonPath('active_context.resource_id', 'about')
            ->assertJsonPath('conversation.context.resource_id', 'about')
            ->assertJsonPath('conversation.messages.0.body', 'Gjør Om oss kortere.');

        $about = Conversation::findOrFail($response->json('conversation.id'));

        $this->assertNotSame($home->id, $about->id);
        $this->assertDatabaseMissing('secretary_messages', [
            'conversation_id' => $home->id,
            'body' => 'Gjør Om oss kortere.',
        ]);
        Bus::assertDispatchedAfterResponse(
            ProcessCpMessage::class,
            fn (ProcessCpMessage $job): bool => $job->messageId === $about->messages()->firstOrFail()->id,
        );
    }

    public function test_follow_up_messages_can_be_queued_while_secretary_is_processing(): void
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
            ->postJson('/cp/secretary/panel/'.$conversation->id.'/messages', ['message' => 'Første melding.'])
            ->assertStatus(202)
            ->assertJsonPath('conversation.messages.0.queue_position', 1);

        $this->actingAs($user)
            ->postJson('/cp/secretary/panel/'.$conversation->id.'/messages', ['message' => 'Oppfølging.'])
            ->assertStatus(202)
            ->assertJsonPath('conversation.messages.0.queue_position', 1)
            ->assertJsonPath('conversation.messages.1.queue_position', 2);

        Bus::assertDispatchedAfterResponseTimes(ProcessCpMessage::class, 2);
    }

    public function test_the_panel_can_publish_a_draft_without_a_page_reload(): void
    {
        $collection = Collection::make('pages')->title('Pages')->revisionsEnabled(true);
        $collection->save();
        Entry::make()
            ->id('home')
            ->collection($collection)
            ->slug('home')
            ->published(true)
            ->data(['title' => 'Før'])
            ->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => ['title' => 'Forsiden'],
        ]);
        $service = app(EntryChangeService::class);
        $changeSet = $service->proposeUpdate($conversation, 'home', ['title' => 'Etter'], 'Ny tittel');
        $service->applyDraft($changeSet, $user);

        $this->actingAs($user)
            ->postJson('/cp/secretary/panel/'.$conversation->id.'/changes/'.$changeSet->id.'/publish')
            ->assertOk()
            ->assertJsonPath('conversation.changes.0.status', 'published')
            ->assertJsonPath('conversation.messages.1.body', 'Publisert: Ny tittel');

        $this->assertSame('Etter', Entry::find('home')->value('title'));
        $this->assertSame('published', $changeSet->fresh()->status);
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

    public function test_an_editor_can_accept_and_reject_one_field_without_touching_the_live_entry(): void
    {
        $collection = Collection::make('pages')->title('Pages')->revisionsEnabled(true);
        $collection->save();
        Entry::make()
            ->id('home')
            ->collection($collection)
            ->slug('home')
            ->published(true)
            ->data(['title' => 'Før'])
            ->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
            'context' => ['title' => 'Forsiden'],
        ]);
        $changes = app(EntryChangeService::class);
        $change = $changes->applyDraft(
            $changes->proposeUpdate($conversation, 'home', ['title' => 'Etter'], 'Ny tittel'),
            $user,
        );

        $this->actingAs($user)
            ->postJson('/cp/secretary/panel/'.$conversation->id.'/changes/'.$change->id.'/review', [
                'target' => 'title',
                'decision' => 'rejected',
            ])
            ->assertOk()
            ->assertJsonPath('conversation.changes.0.review.rejected', 1)
            ->assertJsonPath('conversation.changes.0.review.targets.0.decision', 'rejected');

        $this->assertSame(
            'Før',
            data_get(app(EntrySnapshotter::class)->snapshot(Entry::find('home')), 'data.title'),
        );
        $this->assertSame('Før', Entry::find('home')->value('title'));

        $this->actingAs($user)
            ->postJson('/cp/secretary/panel/'.$conversation->id.'/changes/'.$change->id.'/review', [
                'target' => 'title',
                'decision' => 'accepted',
            ])
            ->assertOk()
            ->assertJsonPath('conversation.changes.0.review.accepted', 1);

        $this->assertSame(
            'Etter',
            data_get(app(EntrySnapshotter::class)->snapshot(Entry::find('home')), 'data.title'),
        );
        $this->assertSame('Før', Entry::find('home')->value('title'));
    }

    public function test_entry_context_can_include_a_validated_active_field(): void
    {
        $collection = Collection::make('pages')->title('Pages')->revisionsEnabled(true);
        $collection->save();
        Entry::make()
            ->id('home')
            ->collection($collection)
            ->slug('home')
            ->published(true)
            ->data(['title' => 'Forsiden'])
            ->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();

        $created = $this->actingAs($user)
            ->postJson('/cp/secretary/panel/conversations', [
                'context_url' => 'http://localhost/cp/collections/pages/entries/home',
                'field_context' => ['handle' => 'title'],
            ])
            ->assertCreated()
            ->assertJsonPath('conversation.context.field.handle', 'title')
            ->assertJsonPath('conversation.context.field.type', 'text');

        $conversation = Conversation::findOrFail($created->json('conversation.id'));
        $this->assertSame('title', data_get($conversation->context, 'cp_context.field.handle'));
    }

    public function test_the_reference_picker_returns_only_authorized_entry_tokens(): void
    {
        $collection = Collection::make('pages')->title('Pages');
        $collection->save();
        Entry::make()
            ->id('about')
            ->collection($collection)
            ->slug('om-oss')
            ->data(['title' => 'Om oss'])
            ->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();

        $this->actingAs($user)
            ->getJson('/cp/secretary/panel/references?q=Om')
            ->assertOk()
            ->assertJsonPath('references.0.id', 'about')
            ->assertJsonPath('references.0.token', '@[Om oss](entry:about)');
    }

    public function test_an_administrator_can_save_a_per_site_editorial_guide(): void
    {
        $user = User::make()->id('admin@example.com')->email('admin@example.com')->makeSuper();
        $user->save();

        $this->actingAs($user)
            ->post('/cp/secretary/settings/editorial', [
                'site' => 'default',
                'audience' => 'Norske redaktører',
                'voice' => 'Kort, varm og tydelig.',
                'terminology' => 'Skriv Statamic med stor S.',
                'avoid' => 'Unngå AI-klisjeer.',
            ])
            ->assertRedirect();

        $guide = app(EditorialStyleGuide::class)->forSite('default');
        $this->assertSame('Norske redaktører', $guide['audience']);
        $this->assertSame('Kort, varm og tydelig.', $guide['voice']);
    }

    public function test_a_draft_preview_endpoint_returns_live_and_tokenized_urls(): void
    {
        $collection = Collection::make('pages')
            ->title('Pages')
            ->routes('/{slug}')
            ->revisionsEnabled(true);
        $collection->save();
        Entry::make()
            ->id('home')
            ->collection($collection)
            ->slug('home')
            ->published(true)
            ->data(['title' => 'Før'])
            ->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'status' => 'open',
        ]);
        $changes = app(EntryChangeService::class);
        $change = $changes->applyDraft(
            $changes->proposeUpdate($conversation, 'home', ['title' => 'Etter']),
            $user,
        );

        $this->actingAs($user)
            ->getJson('/cp/secretary/panel/'.$conversation->id.'/changes/'.$change->id.'/preview')
            ->assertOk()
            ->assertJsonPath('title', 'Etter')
            ->assertJson(fn ($json) => $json
                ->whereType('live_url', 'string')
                ->whereType('draft_url', 'string')
                ->etc());
    }
}
