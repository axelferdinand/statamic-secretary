<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretary\Data\AgentRequest;
use AxelFerdinand\StatamicSecretary\Exceptions\OpenAIRequestFailed;
use AxelFerdinand\StatamicSecretary\OpenAI\OpenAIConfiguration;
use AxelFerdinand\StatamicSecretary\OpenAI\ResponsesAgentClient;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ResponsesAgentClientTest extends TestCase
{
    public function test_it_requires_an_api_key(): void
    {
        config()->set('secretary.openai.api_key');

        $this->expectException(RuntimeException::class);

        app(ResponsesAgentClient::class)->respond(new AgentRequest([
            ['role' => 'user', 'content' => 'Hei'],
        ]));
    }

    public function test_it_sends_a_responses_request_and_extracts_text(): void
    {
        config()->set('secretary.openai.api_key', 'test-key');
        config()->set('secretary.openai.model', 'gpt-test');
        config()->set('secretary.openai.project', 'proj_test');
        config()->set('secretary.openai.max_output_tokens', 1234);

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_123',
                'status' => 'completed',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Utkastet er klart.',
                    ]],
                ]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $response = app(ResponsesAgentClient::class)->respond(new AgentRequest(
            input: [['role' => 'user', 'content' => 'Oppdater forsiden']],
            tools: [['type' => 'function', 'name' => 'read_entry']],
            previousResponseId: 'resp_previous',
            safetyIdentifier: 'user_hash',
        ));

        $this->assertSame('resp_123', $response->id);
        $this->assertSame('Utkastet er klart.', $response->text);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/responses'
            && $request['model'] === 'gpt-test'
            && $request['previous_response_id'] === 'resp_previous'
            && $request['safety_identifier'] === 'user_hash'
            && $request['parallel_tool_calls'] === false
            && $request['store'] === true
            && ! isset($request['include'])
            && $request['max_output_tokens'] === 1234
            && $request->header('OpenAI-Project')[0] === 'proj_test');
    }

    public function test_it_requests_encrypted_reasoning_for_stateless_responses(): void
    {
        config()->set('secretary.openai.api_key', 'test-key');
        config()->set('secretary.openai.store', false);

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_stateless',
                'status' => 'completed',
                'output' => [[
                    'type' => 'reasoning',
                    'encrypted_content' => 'encrypted-reasoning',
                ]],
            ]),
        ]);

        app(ResponsesAgentClient::class)->respond(new AgentRequest([
            ['role' => 'user', 'content' => 'Hei'],
        ]));

        Http::assertSent(fn ($request): bool => $request['store'] === false
            && $request['include'] === ['reasoning.encrypted_content']);
    }

    public function test_it_rejects_an_incomplete_response_instead_of_using_partial_output(): void
    {
        config()->set('secretary.openai.api_key', 'test-key');
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_incomplete',
                'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'Partial']],
                ]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Status: incomplete');

        app(ResponsesAgentClient::class)->respond(new AgentRequest([
            ['role' => 'user', 'content' => 'Hei'],
        ]));
    }

    public function test_it_turns_missing_credits_into_a_safe_editor_message_and_failed_health_check(): void
    {
        config()->set('secretary.openai.api_key', 'test-key');
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'error' => [
                    'code' => 'insufficient_quota',
                    'message' => 'You have no credits remaining.',
                ],
            ], 429),
        ]);

        try {
            app(ResponsesAgentClient::class)->respond(new AgentRequest([
                ['role' => 'user', 'content' => 'Update the homepage'],
            ]));
            $this->fail('The OpenAI quota failure was not reported.');
        } catch (OpenAIRequestFailed $exception) {
            $this->assertSame('credits', $exception->reason);
            $this->assertStringContainsString('no available credits', $exception->publicMessage);
            $this->assertStringNotContainsString('test-key', $exception->publicMessage);
        }

        $health = app(OpenAIConfiguration::class)->health();
        $this->assertFalse($health['passed']);
        $this->assertStringContainsString('no available credits', $health['details']);
    }
}
