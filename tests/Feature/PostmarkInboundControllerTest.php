<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Agent\ConversationService;
use AxelFerdinand\StatamicSecretary\Agent\PublicationIntentDetector;
use AxelFerdinand\StatamicSecretary\Content\EntryChangeService;
use AxelFerdinand\StatamicSecretary\Contracts\AgentClient;
use AxelFerdinand\StatamicSecretary\Data\AgentRequest;
use AxelFerdinand\StatamicSecretary\Data\AgentResponse;
use AxelFerdinand\StatamicSecretary\Jobs\ProcessCpMessage;
use AxelFerdinand\StatamicSecretary\Jobs\ProcessInboundEmail;
use AxelFerdinand\StatamicSecretary\Mail\SecretaryReply;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Models\Message;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

class PostmarkInboundControllerTest extends TestCase
{
    public function test_queue_jobs_allow_fifo_deferrals_beyond_one_model_timeout(): void
    {
        config()->set('secretary.limits.job_timeout', 1200);
        $minimumDeadline = now()->addDay()->getTimestamp();

        $email = new ProcessInboundEmail('email-message');
        $cp = new ProcessCpMessage('cp-message');

        $this->assertGreaterThanOrEqual($minimumDeadline, $email->retryUntil);
        $this->assertGreaterThanOrEqual($minimumDeadline, $cp->retryUntil);
        $this->assertSame(3, $email->maxExceptions);
        $this->assertSame(3, $cp->maxExceptions);
    }

    public function test_a_disabled_inbound_endpoint_returns_a_permanent_rejection(): void
    {
        $this->postJson('/_secretary/webhooks/postmark/inbound', [])->assertForbidden();
    }

    public function test_the_outbound_secretary_address_cannot_trigger_a_reply_loop(): void
    {
        $this->configureInboundEmail();
        config()->set('secretary.email.allowed_senders', []);
        User::make()->id('secretary@example.test')->email('secretary@example.test')->makeSuper()->save();
        Bus::fake();

        $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'self-sender-loop',
            'Subject' => 'Re: Secretary',
            'TextBody' => 'A Secretary reply forwarded back to the inbound address.',
            'FromFull' => ['Email' => 'secretary@example.test'],
            'Headers' => $this->authenticatedHeaders(),
        ])->assertOk()->assertJson(['accepted' => true, 'ignored' => true]);

        $this->assertDatabaseCount('secretary_conversations', 0);
        $this->assertDatabaseCount('secretary_messages', 0);
        Bus::assertNothingDispatched();
    }

    public function test_a_pre_tagged_secretary_address_is_rejected_before_mail_is_stored(): void
    {
        $this->configureInboundEmail();
        config()->set('secretary.email.address', 'secretary+existing@example.test');
        User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper()->save();

        $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'bad-thread-address',
            'TextBody' => 'Oppdater forsiden.',
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => $this->authenticatedHeaders(),
        ])->assertServiceUnavailable();

        $this->assertDatabaseCount('secretary_conversations', 0);
        $this->assertDatabaseCount('secretary_messages', 0);
    }

    public function test_the_inbound_webhook_explicitly_excludes_laravels_active_csrf_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('secretary.web.postmark.inbound');

        $this->assertNotNull($route);
        $this->assertContains(PreventRequestForgery::class, $route->excludedMiddleware());
    }

    public function test_email_and_cp_jobs_share_a_per_conversation_overlap_lock(): void
    {
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create(['channel' => 'email', 'user_id' => $user->id(), 'email' => $user->email()]);
        $email = $conversation->messages()->create([
            'direction' => 'inbound',
            'channel' => 'email',
            'role' => 'user',
            'body' => 'E-post',
        ]);
        $cp = $conversation->messages()->create([
            'direction' => 'inbound',
            'channel' => 'cp',
            'role' => 'user',
            'body' => 'CP',
        ]);
        $emailJob = new ProcessInboundEmail($email->id);
        $cpJob = new ProcessCpMessage($cp->id);

        $this->assertSame(
            $emailJob->middleware()[0]->getLockKey($emailJob),
            $cpJob->middleware()[0]->getLockKey($cpJob),
        );
    }

    public function test_it_authenticates_records_and_queues_a_postmark_email(): void
    {
        config()->set('secretary.email.enabled', true);
        config()->set('secretary.email.address', 'secretary@example.test');
        config()->set('secretary.email.from_address', 'secretary@example.test');
        config()->set('secretary.email.allowed_senders', ['editor@example.com']);
        config()->set('secretary.email.postmark.username', 'postmark');
        config()->set('secretary.email.postmark.password', 'webhook-secret');
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        Bus::fake();

        $response = $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'postmark-message-1',
            'MailboxHash' => '',
            'Subject' => 'Endre forsiden',
            'TextBody' => 'Bytt tittel på forsiden.',
            'HtmlBody' => '',
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => [
                ['Name' => 'X-Spam-Score', 'Value' => '-0.1'],
                ['Name' => 'X-Spam-Tests', 'Value' => 'DKIM_SIGNED,DKIM_VALID,DKIM_VALID_AU'],
                ['Name' => 'Message-ID', 'Value' => '<inbound-1@example.com>'],
            ],
        ]);

        $response->assertOk()->assertJson(['accepted' => true]);
        $inbound = Message::where('provider_message_id', 'postmark-message-1')->first();
        $this->assertSame('Bytt tittel på forsiden.', $inbound->body);
        $this->assertSame('<inbound-1@example.com>', data_get($inbound->metadata, 'rfc_message_id'));
        Bus::assertDispatched(ProcessInboundEmail::class);

        $this->withBasicAuth('postmark', 'webhook-secret')
            ->postJson('/_secretary/webhooks/postmark/inbound', [
                'MessageID' => 'postmark-message-1',
                'FromFull' => ['Email' => 'editor@example.com'],
            ])
            ->assertOk()
            ->assertJson(['duplicate' => true]);

        Bus::assertDispatchedAfterResponseTimes(ProcessInboundEmail::class, 2);
    }

    public function test_ambiguous_rfc_message_ids_are_not_reflected_into_replies(): void
    {
        $this->configureInboundEmail();
        User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper()->save();
        Bus::fake();

        $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'ambiguous-rfc-message',
            'TextBody' => 'Oppdater forsiden.',
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => [
                ...$this->authenticatedHeaders(),
                ['Name' => 'Message-ID', 'Value' => '<first@example.com>'],
                ['Name' => 'message-id', 'Value' => "<second@example.com>\r\nBcc: attacker@example.com"],
            ],
        ])->assertOk();

        $message = Message::where('provider_message_id', 'ambiguous-rfc-message')->firstOrFail();

        $this->assertNull(data_get($message->metadata, 'rfc_message_id'));
        Bus::assertDispatched(ProcessInboundEmail::class);
    }

    public function test_a_persistent_queue_receives_the_email_job_before_the_webhook_response(): void
    {
        $this->configureInboundEmail();
        User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper()->save();
        config()->set('queue.default', 'database');
        Bus::fake();

        $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'durable-message',
            'TextBody' => 'Oppdater forsiden.',
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => $this->authenticatedHeaders(),
        ])->assertOk();

        Bus::assertDispatchedTimes(ProcessInboundEmail::class, 1);
        Bus::assertDispatchedAfterResponseTimes(ProcessInboundEmail::class, 0);
    }

    public function test_postmark_redelivery_recovers_after_the_first_persistent_queue_push_fails(): void
    {
        $this->configureInboundEmail();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        config()->set('queue.default', 'database');
        $dispatches = 0;
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')
            ->twice()
            ->with(Mockery::type(ProcessInboundEmail::class))
            ->andReturnUsing(function () use (&$dispatches): mixed {
                if (++$dispatches === 1) {
                    throw new RuntimeException('Queue temporarily unavailable.');
                }

                return null;
            });
        $this->app->instance(Dispatcher::class, $bus);
        $payload = [
            'MessageID' => 'queue-recovery-message',
            'TextBody' => 'Oppdater forsiden.',
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => $this->authenticatedHeaders(),
        ];

        $this->withBasicAuth('postmark', 'webhook-secret')
            ->postJson('/_secretary/webhooks/postmark/inbound', $payload)
            ->assertServerError();

        $this->assertDatabaseHas('secretary_messages', ['provider_message_id' => 'queue-recovery-message']);

        $this->withBasicAuth('postmark', 'webhook-secret')
            ->postJson('/_secretary/webhooks/postmark/inbound', $payload)
            ->assertOk()
            ->assertJson(['accepted' => true, 'duplicate' => true]);

        $this->assertSame(2, $dispatches);
        $this->assertDatabaseCount('secretary_messages', 1);
    }

    public function test_a_duplicate_provider_id_cannot_be_replayed_as_another_sender(): void
    {
        $this->configureInboundEmail();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = app(ConversationService::class)->start('email', $user, 'editor@example.com', 'original-thread');
        app(ConversationService::class)->recordInbound(
            $conversation,
            'Opprinnelig melding.',
            $user,
            'email',
            [],
            'replayed-message-id',
        );
        Bus::fake();

        $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'replayed-message-id',
            'FromFull' => ['Email' => 'attacker@example.com'],
        ])->assertForbidden();

        Bus::assertNotDispatched(ProcessInboundEmail::class);
    }

    public function test_it_rejects_a_webhook_without_basic_authentication(): void
    {
        config()->set('secretary.email.enabled', true);
        config()->set('secretary.email.address', 'secretary@example.test');
        config()->set('secretary.email.from_address', 'secretary@example.test');
        config()->set('secretary.email.postmark.username', 'postmark');
        config()->set('secretary.email.postmark.password', 'webhook-secret');

        $this->postJson('/_secretary/webhooks/postmark/inbound', [])->assertUnauthorized();
    }

    public function test_missing_openai_configuration_returns_a_retryable_error_without_storing_mail(): void
    {
        $this->configureInboundEmail();
        config()->set('secretary.openai.api_key');
        User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper()->save();

        $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'waiting-for-openai',
            'TextBody' => 'Oppdater forsiden.',
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => $this->authenticatedHeaders(),
        ])->assertServiceUnavailable();

        $this->assertDatabaseCount('secretary_conversations', 0);
        $this->assertDatabaseCount('secretary_messages', 0);
    }

    public function test_a_publication_command_can_be_accepted_without_openai_configuration(): void
    {
        $this->configureInboundEmail();
        config()->set('secretary.openai.api_key');
        User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper()->save();
        Bus::fake();

        $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'publish-without-openai',
            'TextBody' => 'Publiser utkastet.',
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => $this->authenticatedHeaders(),
        ])->assertOk();

        $this->assertDatabaseHas('secretary_messages', ['provider_message_id' => 'publish-without-openai']);
        Bus::assertDispatched(ProcessInboundEmail::class);
    }

    public function test_it_rejects_an_oversized_authenticated_webhook_before_processing_content(): void
    {
        $this->configureInboundEmail();
        config()->set('secretary.limits.max_webhook_bytes', 1024);

        $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'oversized-message',
            'TextBody' => str_repeat('x', 2000),
            'FromFull' => ['Email' => 'editor@example.com'],
        ])->assertForbidden();

        $this->assertDatabaseMissing('secretary_messages', ['provider_message_id' => 'oversized-message']);
    }

    public function test_it_rejects_an_instruction_over_the_agent_input_limit_without_requesting_retries(): void
    {
        $this->configureInboundEmail();
        config()->set('secretary.limits.max_input_characters', 20);
        User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper()->save();

        $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'long-instruction',
            'TextBody' => str_repeat('æ', 21),
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => $this->authenticatedHeaders(),
        ])->assertForbidden();

        $this->assertDatabaseMissing('secretary_messages', ['provider_message_id' => 'long-instruction']);
        $this->assertDatabaseCount('secretary_conversations', 0);
    }

    public function test_an_email_without_a_readable_instruction_does_not_create_an_empty_conversation(): void
    {
        $this->configureInboundEmail();
        User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper()->save();

        $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'empty-instruction',
            'TextBody' => '   ',
            'HtmlBody' => '<p> </p>',
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => $this->authenticatedHeaders(),
        ])->assertForbidden();

        $this->assertDatabaseCount('secretary_conversations', 0);
        $this->assertDatabaseCount('secretary_messages', 0);
    }

    public function test_it_rejects_an_allowlisted_but_unauthenticated_sender(): void
    {
        config()->set('secretary.email.enabled', true);
        config()->set('secretary.email.address', 'secretary@example.test');
        config()->set('secretary.email.from_address', 'secretary@example.test');
        config()->set('secretary.email.allowed_senders', ['editor@example.com']);
        config()->set('secretary.email.postmark.username', 'postmark');
        config()->set('secretary.email.postmark.password', 'webhook-secret');
        User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper()->save();

        $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'spoofed-message',
            'TextBody' => 'Publiser alt.',
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => [['Name' => 'X-Spam-Tests', 'Value' => 'SPF_FAIL,DKIM_INVALID']],
        ])->assertForbidden();

        $this->assertDatabaseMissing('secretary_messages', ['provider_message_id' => 'spoofed-message']);
    }

    public function test_spf_pass_alone_does_not_authenticate_the_visible_from_address(): void
    {
        $this->configureInboundEmail();
        User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper()->save();

        $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'spf-only-message',
            'TextBody' => 'Oppdater forsiden.',
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => [
                ['Name' => 'X-Spam-Score', 'Value' => '-0.1'],
                ['Name' => 'X-Spam-Tests', 'Value' => 'SPF_PASS'],
            ],
        ])->assertForbidden();

        $this->assertDatabaseMissing('secretary_messages', ['provider_message_id' => 'spf-only-message']);
    }

    public function test_mailbox_hash_continues_the_same_email_conversation(): void
    {
        $this->configureInboundEmail();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        Bus::fake();

        $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'thread-message-1',
            'Subject' => 'Oppdater siden',
            'TextBody' => 'Første instruksjon.',
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => $this->authenticatedHeaders(),
        ])->assertOk();
        $conversation = Conversation::where('external_thread_id', 'thread-message-1')->firstOrFail();

        $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'thread-message-2',
            'MailboxHash' => $conversation->id,
            'Subject' => 'Re: Oppdater siden',
            'TextBody' => "Andre instruksjon.\n\n> Første instruksjon.",
            'StrippedTextReply' => 'Andre instruksjon.',
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => $this->authenticatedHeaders(),
        ])->assertOk();

        $this->assertCount(2, $conversation->messages()->where('direction', 'inbound')->get());
        $this->assertSame('Andre instruksjon.', Message::where('provider_message_id', 'thread-message-2')->first()->body);
    }

    public function test_an_unknown_mailbox_hash_is_rejected_without_creating_a_new_thread(): void
    {
        $this->configureInboundEmail();
        User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper()->save();

        $this->withBasicAuth('postmark', 'webhook-secret')->postJson('/_secretary/webhooks/postmark/inbound', [
            'MessageID' => 'unknown-thread-message',
            'MailboxHash' => '01jx8w6x68g3a9hpv3d6t09c9m',
            'TextBody' => 'Fortsett med endringen.',
            'FromFull' => ['Email' => 'editor@example.com'],
            'Headers' => $this->authenticatedHeaders(),
        ])->assertForbidden();

        $this->assertDatabaseCount('secretary_conversations', 0);
        $this->assertDatabaseCount('secretary_messages', 0);
    }

    public function test_the_email_job_processes_and_sends_a_threaded_provider_neutral_reply(): void
    {
        config()->set('secretary.email.address', 'secretary@example.test');
        config()->set('secretary.email.from_address', 'secretary@example.test');
        $this->app->bind(AgentClient::class, fn () => new class implements AgentClient
        {
            public function respond(AgentRequest $request): AgentResponse
            {
                return new AgentResponse('resp_email', 'completed', [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'Utkastet er klart.']],
                ]], 'Utkastet er klart.');
            }
        });
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $service = app(ConversationService::class);
        $conversation = $service->start('email', $user, 'editor@example.com', 'message-1');
        $message = $service->recordInbound(
            $conversation,
            'Oppdater forsiden.',
            $user,
            'email',
            [
                'subject' => "Forsiden\nmed ny tittel",
                'rfc_message_id' => '<original-message@example.com>',
            ],
            'message-1',
        );
        Mail::fake();

        (new ProcessInboundEmail($message->id))->handle($service, app(PublicationIntentDetector::class));

        $this->assertNotNull($message->fresh()->processed_at);
        $this->assertSame('Utkastet er klart.', $conversation->messages()->where('role', 'assistant')->first()->body);
        Mail::assertSent(SecretaryReply::class, function (SecretaryReply $mail) use ($conversation): bool {
            $mail->assertSeeInText('Utkastet er klart.')
                ->assertSeeInText('Åpne samtalen i Secretary:');
            $headers = $mail->headers();

            return $mail->envelope()->replyTo[0]->address === 'secretary+'.$conversation->id.'@example.test'
                && $mail->envelope()->subject === 'Re: Forsiden med ny tittel'
                && $headers->references === ['<original-message@example.com>']
                && $headers->text['In-Reply-To'] === '<original-message@example.com>'
                && str_contains($mail->render(), 'Åpne samtalen i Secretary');
        });
    }

    public function test_the_email_reply_links_directly_to_a_single_entry_draft(): void
    {
        config()->set('secretary.email.address', 'secretary@example.test');
        config()->set('secretary.email.from_address', 'secretary@example.test');
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
        $conversation = app(ConversationService::class)->start(
            'email',
            $user,
            'editor@example.com',
            'draft-link-thread',
        );
        $changes = app(EntryChangeService::class);
        $draft = $changes->proposeUpdate(
            $conversation,
            'home',
            ['title' => 'Etter'],
            'Endret tittel',
        );
        $changes->applyDraft($draft, $user);
        $reply = $conversation->messages()->create([
            'direction' => 'outbound',
            'channel' => 'email',
            'role' => 'assistant',
            'body' => "Tittelen på forsiden er endret fra «Før» til «Etter».\n\nBerørt side: Forsiden (`/`)\nStatus: Klar som utkast – ikke publisert.",
            'metadata' => ['change_set_ids' => [$draft->id]],
            'processed_at' => now(),
        ]);
        $mail = new SecretaryReply($conversation, $reply);
        $draftUrl = Entry::find('home')->editUrl();
        $publicUrl = Entry::find('home')->absoluteUrl();
        $conversationUrl = $draftUrl.'?secretary='.$conversation->id;

        $mail->assertSeeInText('Åpne utkastet i Statamic:')
            ->assertSeeInText($conversationUrl)
            ->assertSeeInText('Berørt side: Forsiden — '.$publicUrl)
            ->assertDontSeeInText('Fortsett samtalen i Secretary:')
            ->assertDontSeeInText('Endringer i denne meldingen')
            ->assertDontSeeInText('Klargjorte endringer')
            ->assertDontSeeInText('Berørt side: Forsiden (`/`)');
        $rendered = $mail->render();
        $this->assertStringNotContainsString('href="'.$draftUrl.'"', $rendered);
        $this->assertStringContainsString('href="'.$publicUrl.'"', $rendered);
        $this->assertStringContainsString('href="'.$conversationUrl.'"', $rendered);
        $this->assertStringNotContainsString('Fortsett samtalen i Secretary', $rendered);
        $this->assertLessThan(
            strpos($rendered, 'Status: Klar som utkast'),
            strpos($rendered, 'Berørt side:'),
        );
    }

    public function test_the_email_job_reuses_a_stored_reply_after_a_mail_failure_without_calling_the_agent_again(): void
    {
        config()->set('secretary.email.address', 'secretary@example.test');
        config()->set('secretary.email.from_address', 'secretary@example.test');
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

                return new AgentResponse('resp_email_retry', 'completed', [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'Samme lagrede svar.']],
                ]], 'Samme lagrede svar.');
            }
        });
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $service = app(ConversationService::class);
        $conversation = $service->start('email', $user, 'editor@example.com', 'message-retry');
        $message = $service->recordInbound($conversation, 'Oppdater.', $user, 'email', [], 'message-retry');
        $service->respondTo($message, $user);
        $job = new ProcessInboundEmail($message->id);

        Mail::fake();
        $job->handle($service, app(PublicationIntentDetector::class));
        $job->handle($service, app(PublicationIntentDetector::class));

        $this->assertSame(1, $calls->count);
        $this->assertCount(1, $conversation->messages()->where('direction', 'outbound')->get());
        Mail::assertSentCount(1);
    }

    public function test_the_email_job_sends_a_failure_reply_after_exhausting_retries(): void
    {
        config()->set('secretary.email.address', 'secretary@example.test');
        config()->set('secretary.email.from_address', 'secretary@example.test');
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $service = app(ConversationService::class);
        $conversation = $service->start('email', $user, 'editor@example.com', 'failed-message');
        $message = $service->recordInbound($conversation, 'Oppdater.', $user, 'email', [], 'failed-message');
        Mail::fake();

        (new ProcessInboundEmail($message->id))->failed(new RuntimeException('Simulated processing failure'));

        $this->assertNotNull($message->fresh()->processed_at);
        $this->assertNotNull(data_get($message->fresh()->metadata, 'processing_error'));
        $this->assertTrue((bool) data_get($conversation->messages()->where('direction', 'outbound')->first()->metadata, 'processing_failed'));
        Mail::assertSent(SecretaryReply::class);
    }

    public function test_a_sync_email_job_contains_after_response_failures_and_sends_a_safe_reply(): void
    {
        config()->set('secretary.email.address', 'secretary@example.test');
        config()->set('secretary.email.from_address', 'secretary@example.test');
        config()->set('queue.default', 'sync');
        $this->app->bind(AgentClient::class, fn () => new class implements AgentClient
        {
            public function respond(AgentRequest $request): AgentResponse
            {
                throw new RuntimeException('Internal model failure with secret details.');
            }
        });
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $service = app(ConversationService::class);
        $conversation = $service->start('email', $user, 'editor@example.com', 'sync-failure');
        $message = $service->recordInbound($conversation, 'Oppdater.', $user, 'email', [], 'sync-failure');
        Mail::fake();

        (new ProcessInboundEmail($message->id))->handle($service, app(PublicationIntentDetector::class));

        $message->refresh();
        $this->assertNotNull($message->processed_at);
        $this->assertSame(
            'Secretary kunne ikke behandle e-posten. Kontroller loggen og prøv igjen.',
            data_get($message->metadata, 'processing_error'),
        );
        $this->assertStringNotContainsString('secret details', json_encode($message->metadata));
        $this->assertTrue((bool) data_get(
            $conversation->messages()->where('direction', 'outbound')->firstOrFail()->metadata,
            'processing_failed',
        ));
        Mail::assertSent(SecretaryReply::class);
    }

    public function test_an_authenticated_explicit_email_command_publishes_the_requested_draft(): void
    {
        config()->set('secretary.email.address', 'secretary@example.test');
        config()->set('secretary.email.from_address', 'secretary@example.test');
        config()->set('secretary.email.allow_publishing', true);
        $collection = Collection::make('pages')->title('Pages')->revisionsEnabled(true);
        $collection->save();
        Entry::make()->id('home')->collection($collection)->slug('home')->published(true)->data(['title' => 'Før'])->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversations = app(ConversationService::class);
        $conversation = $conversations->start('email', $user, 'editor@example.com', 'publish-thread');
        $changes = app(EntryChangeService::class);
        $draft = $changes->proposeUpdate($conversation, 'home', ['title' => 'Etter'], 'Endret tittel');
        $changes->applyDraft($draft, $user);
        $message = $conversations->recordInbound(
            $conversation,
            'Publiser '.$draft->id,
            $user,
            'email',
            ['sender_authenticated' => true],
            'publish-message',
        );
        Mail::fake();

        (new ProcessInboundEmail($message->id))->handle($conversations, app(PublicationIntentDetector::class));

        $this->assertSame('Etter', Entry::find('home')->value('title'));
        $this->assertSame('published', $draft->fresh()->status);
        Mail::assertSent(SecretaryReply::class, fn (SecretaryReply $mail): bool => str_contains($mail->render(), 'Endret tittel'));
    }

    public function test_a_publication_reply_recovers_when_content_was_published_before_the_reply_was_stored(): void
    {
        $collection = Collection::make('pages')->title('Pages')->revisionsEnabled(true);
        $collection->save();
        Entry::make()->id('home')->collection($collection)->slug('home')->published(true)->data(['title' => 'Før'])->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversations = app(ConversationService::class);
        $conversation = $conversations->start('email', $user, 'editor@example.com', 'crash-thread');
        $changes = app(EntryChangeService::class);
        $draft = $changes->proposeUpdate($conversation, 'home', ['title' => 'Etter'], 'Endret tittel');
        $changes->applyDraft($draft, $user);
        $message = $conversations->recordInbound(
            $conversation,
            'Publiser '.$draft->id,
            $user,
            'email',
            ['sender_authenticated' => true, 'change_set_ids' => [$draft->id]],
            'crash-publish-message',
        );

        $changes->publish($draft->fresh(), $user);
        $reply = $conversations->respondTo($message, $user);

        $this->assertSame('Publisert: Endret tittel', $reply->body);
        $this->assertSame($message->id, $reply->reply_to_message_id);
        $this->assertCount(1, $conversation->messages()->where('direction', 'outbound')->get());
    }

    private function configureInboundEmail(): void
    {
        config()->set('secretary.email.enabled', true);
        config()->set('secretary.email.address', 'secretary@example.test');
        config()->set('secretary.email.from_address', 'secretary@example.test');
        config()->set('secretary.email.allowed_senders', ['editor@example.com']);
        config()->set('secretary.email.postmark.username', 'postmark');
        config()->set('secretary.email.postmark.password', 'webhook-secret');
    }

    /** @return array<int, array{Name: string, Value: string}> */
    private function authenticatedHeaders(): array
    {
        return [
            ['Name' => 'X-Spam-Score', 'Value' => '-0.1'],
            ['Name' => 'X-Spam-Tests', 'Value' => 'DKIM_SIGNED,DKIM_VALID,DKIM_VALID_AU'],
        ];
    }
}
