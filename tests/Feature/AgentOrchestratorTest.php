<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Agent\AgentOrchestrator;
use AxelFerdinand\StatamicSecretary\Content\ContentResourceCatalog;
use AxelFerdinand\StatamicSecretary\Content\EntryCatalog;
use AxelFerdinand\StatamicSecretary\Content\EntryChangeService;
use AxelFerdinand\StatamicSecretary\Content\StagedContentChangeService;
use AxelFerdinand\StatamicSecretary\Contracts\AgentClient;
use AxelFerdinand\StatamicSecretary\Data\AgentRequest;
use AxelFerdinand\StatamicSecretary\Data\AgentResponse;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\User;

class AgentOrchestratorTest extends TestCase
{
    public function test_zero_argument_tool_properties_encode_as_a_json_object(): void
    {
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);
        $inbound = $conversation->messages()->create([
            'direction' => 'inbound',
            'channel' => 'cp',
            'role' => 'user',
            'body' => 'Hvilke samlinger finnes?',
        ]);
        $client = new class implements AgentClient
        {
            public ?AgentRequest $request = null;

            public function respond(AgentRequest $request): AgentResponse
            {
                $this->request = $request;

                return new AgentResponse('resp_final', 'completed', [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'Klart.']],
                ]], 'Klart.');
            }
        };
        $orchestrator = new AgentOrchestrator(
            $client,
            app(EntryCatalog::class),
            app(EntryChangeService::class),
            app(ContentResourceCatalog::class),
            app(StagedContentChangeService::class),
        );

        $orchestrator->respond($conversation, $inbound, $user);

        $tool = collect($client->request?->tools)->firstWhere('name', 'list_collections');

        $this->assertInstanceOf(\stdClass::class, $tool['parameters']['properties']);
        $this->assertStringContainsString(
            '"properties":{}',
            json_encode($tool, JSON_THROW_ON_ERROR),
        );
    }

    public function test_it_stages_non_revision_content_through_an_inspect_first_tool_loop(): void
    {
        $set = GlobalSet::make('company')->title('Company');
        $set->save();
        $set->in('default')->data(['phone' => '11 11 11 11'])->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);
        $fingerprint = app(ContentResourceCatalog::class)->read($user, 'global', 'company::default', 'default')['fingerprint'];
        $inbound = $conversation->messages()->create([
            'direction' => 'inbound',
            'channel' => 'cp',
            'role' => 'user',
            'body' => 'Oppdater telefonnummeret.',
        ]);
        $client = new class($fingerprint) implements AgentClient
        {
            public int $requests = 0;

            public function __construct(private readonly string $fingerprint) {}

            public function respond(AgentRequest $request): AgentResponse
            {
                $this->requests++;

                return match ($this->requests) {
                    1 => new AgentResponse('resp_sources', 'completed', [[
                        'type' => 'function_call',
                        'call_id' => 'sources',
                        'name' => 'list_content_sources',
                        'arguments' => '{}',
                    ]], ''),
                    2 => new AgentResponse('resp_schema', 'completed', [[
                        'type' => 'function_call',
                        'call_id' => 'schema',
                        'name' => 'describe_content_schema',
                        'arguments' => json_encode([
                            'resource_type' => 'global',
                            'source' => 'company',
                            'blueprint' => null,
                        ]),
                    ]], ''),
                    3 => new AgentResponse('resp_read', 'completed', [[
                        'type' => 'function_call',
                        'call_id' => 'read',
                        'name' => 'read_content_resource',
                        'arguments' => json_encode([
                            'resource_type' => 'global',
                            'resource_id' => 'company::default',
                            'site' => 'default',
                        ]),
                    ]], ''),
                    4 => new AgentResponse('resp_draft', 'completed', [[
                        'type' => 'function_call',
                        'call_id' => 'draft',
                        'name' => 'update_content_draft',
                        'arguments' => json_encode([
                            'resource_type' => 'global',
                            'resource_id' => 'company::default',
                            'site' => 'default',
                            'expected_fingerprint' => $this->fingerprint,
                            'patch_json' => json_encode(['phone' => '22 22 22 22']),
                            'summary' => 'Oppdaterte telefonnummeret',
                        ]),
                    ]], ''),
                    default => new AgentResponse('resp_final', 'completed', [[
                        'type' => 'message',
                        'content' => [['type' => 'output_text', 'text' => 'Telefonnummeret er klart som utkast.']],
                    ]], 'Telefonnummeret er klart som utkast.'),
                };
            }
        };
        $orchestrator = new AgentOrchestrator(
            $client,
            app(EntryCatalog::class),
            app(EntryChangeService::class),
            app(ContentResourceCatalog::class),
            app(StagedContentChangeService::class),
        );

        $reply = $orchestrator->respond($conversation, $inbound, $user);

        $this->assertSame('Telefonnummeret er klart som utkast.', $reply->body);
        $this->assertSame('11 11 11 11', GlobalSet::findByHandle('company')->in('default')->get('phone'));
        $this->assertSame('draft', $conversation->changeSets()->first()->status);
        $this->assertSame('global', $conversation->changeSets()->first()->resource_type);
        $this->assertSame(5, $client->requests);
    }

    public function test_it_executes_a_strict_draft_tool_loop_without_publishing(): void
    {
        $collection = Collection::make('pages')->title('Pages')->revisionsEnabled(true);
        $collection->save();
        Entry::make()->id('home')->collection($collection)->slug('home')->published(true)->data(['title' => 'Før'])->save();
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);
        $fingerprint = app(EntryCatalog::class)->read($user, 'home')['fingerprint'];
        $inbound = $conversation->messages()->create([
            'direction' => 'inbound',
            'channel' => 'cp',
            'role' => 'user',
            'body' => 'Bytt tittel på forsiden.',
        ]);
        $client = new class($fingerprint) implements AgentClient
        {
            public array $requests = [];

            public function __construct(private readonly string $fingerprint) {}

            public function respond(AgentRequest $request): AgentResponse
            {
                $this->requests[] = $request;

                if (count($this->requests) === 1) {
                    return new AgentResponse('resp_tool', 'completed', [
                        [
                            'type' => 'function_call',
                            'call_id' => 'call_read',
                            'name' => 'read_entry',
                            'arguments' => json_encode(['entry_id' => 'home']),
                        ],
                        [
                            'type' => 'function_call',
                            'call_id' => 'call_update',
                            'name' => 'update_entry_draft',
                            'arguments' => json_encode([
                                'entry_id' => 'home',
                                'expected_fingerprint' => $this->fingerprint,
                                'patch_json' => json_encode(['title' => 'Etter']),
                                'summary' => 'Byttet tittel',
                            ]),
                        ],
                        [
                            'type' => 'function_call',
                            'call_id' => 'call_update_retry',
                            'name' => 'update_entry_draft',
                            'arguments' => json_encode([
                                'entry_id' => 'home',
                                'expected_fingerprint' => $this->fingerprint,
                                'patch_json' => json_encode(['title' => 'Et annet retry-resultat']),
                                'summary' => 'Nondeterministisk retry-resultat',
                            ]),
                        ],
                    ], '');
                }

                return new AgentResponse('resp_final', 'completed', [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'Tittelen er oppdatert i et utkast.']],
                ]], 'Tittelen er oppdatert i et utkast.');
            }
        };
        $orchestrator = new AgentOrchestrator(
            $client,
            app(EntryCatalog::class),
            app(EntryChangeService::class),
            app(ContentResourceCatalog::class),
            app(StagedContentChangeService::class),
        );

        $reply = $orchestrator->respond($conversation, $inbound, $user);

        $this->assertSame('Tittelen er oppdatert i et utkast.', $reply->body);
        $this->assertSame('Før', Entry::find('home')->value('title'));
        $this->assertSame('Etter', Entry::find('home')->fromWorkingCopy()->value('title'));
        $this->assertSame('resp_tool', $client->requests[1]->previousResponseId);
        $this->assertSame('function_call_output', $client->requests[1]->input[0]['type']);
        $this->assertCount(1, $reply->metadata['change_set_ids']);
        $this->assertCount(1, $conversation->changeSets()->get());
        $this->assertSame(['title' => 'Etter'], $conversation->changeSets()->first()->patch);
    }

    public function test_it_preserves_local_state_for_tool_calls_when_openai_storage_is_disabled(): void
    {
        config()->set('secretary.openai.store', false);
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create(['channel' => 'cp', 'user_id' => $user->id()]);
        $inbound = $conversation->messages()->create([
            'direction' => 'inbound',
            'channel' => 'cp',
            'role' => 'user',
            'body' => 'Hvilke samlinger finnes?',
        ]);
        $client = new class implements AgentClient
        {
            public array $requests = [];

            public function respond(AgentRequest $request): AgentResponse
            {
                $this->requests[] = $request;

                return count($this->requests) === 1
                    ? new AgentResponse('resp_tool', 'completed', [
                        [
                            'type' => 'reasoning',
                            'encrypted_content' => 'encrypted-reasoning',
                        ],
                        [
                            'type' => 'function_call',
                            'call_id' => 'call_1',
                            'name' => 'list_collections',
                            'arguments' => '{}',
                        ],
                    ], '')
                    : new AgentResponse('resp_final', 'completed', [[
                        'type' => 'message',
                        'content' => [['type' => 'output_text', 'text' => 'Ingen samlinger ennå.']],
                    ]], 'Ingen samlinger ennå.');
            }
        };
        $orchestrator = new AgentOrchestrator(
            $client,
            app(EntryCatalog::class),
            app(EntryChangeService::class),
            app(ContentResourceCatalog::class),
            app(StagedContentChangeService::class),
        );

        $orchestrator->respond($conversation, $inbound, $user);

        $this->assertNull($client->requests[1]->previousResponseId);
        $this->assertSame(
            hash_hmac('sha256', 'statamic-secretary:'.$user->id(), (string) config('app.key')),
            $client->requests[0]->safetyIdentifier,
        );
        $this->assertSame(
            ['user', 'reasoning', 'function_call', 'function_call_output'],
            array_map(fn (array $item): string => $item['type'] ?? $item['role'], $client->requests[1]->input),
        );
        $this->assertSame('encrypted-reasoning', $client->requests[1]->input[1]['encrypted_content']);
        $this->assertNull($conversation->fresh()->openai_response_id);
    }

    public function test_it_only_commits_a_stored_response_id_after_the_full_tool_turn_finishes(): void
    {
        $user = User::make()->id('editor@example.com')->email('editor@example.com')->makeSuper();
        $user->save();
        $conversation = Conversation::create([
            'channel' => 'cp',
            'user_id' => $user->id(),
            'openai_response_id' => 'resp_previous_complete',
        ]);
        $inbound = $conversation->messages()->create([
            'direction' => 'inbound',
            'channel' => 'cp',
            'role' => 'user',
            'body' => 'Hvilke samlinger finnes?',
        ]);
        $client = new class implements AgentClient
        {
            private int $calls = 0;

            public function respond(AgentRequest $request): AgentResponse
            {
                if (++$this->calls === 1) {
                    return new AgentResponse('resp_incomplete_tool_turn', 'completed', [[
                        'type' => 'function_call',
                        'call_id' => 'call_list',
                        'name' => 'list_collections',
                        'arguments' => '{}',
                    ]], '');
                }

                throw new \RuntimeException('Simulated worker interruption');
            }
        };
        $orchestrator = new AgentOrchestrator(
            $client,
            app(EntryCatalog::class),
            app(EntryChangeService::class),
            app(ContentResourceCatalog::class),
            app(StagedContentChangeService::class),
        );

        try {
            $orchestrator->respond($conversation, $inbound, $user);
            $this->fail('The simulated interruption should escape the orchestrator.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated worker interruption', $exception->getMessage());
        }

        $this->assertSame('resp_previous_complete', $conversation->fresh()->openai_response_id);
    }
}
