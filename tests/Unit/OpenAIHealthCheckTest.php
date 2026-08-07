<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretary\OpenAI\OpenAIConfiguration;
use AxelFerdinand\StatamicSecretary\OpenAI\OpenAIHealthCheck;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class OpenAIHealthCheckTest extends TestCase
{
    public function test_it_runs_a_tiny_live_request_and_records_success(): void
    {
        config()->set('secretary.openai.api_key', 'test-key');
        config()->set('secretary.openai.model', 'gpt-test');
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_health',
                'status' => 'completed',
                'output' => [],
            ]),
        ]);

        $health = app(OpenAIHealthCheck::class)->run();

        $this->assertTrue($health['passed']);
        $this->assertStringContainsString('credits are ready', $health['details']);
        $this->assertTrue(app(OpenAIConfiguration::class)->health()['passed']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/responses'
            && $request['model'] === 'gpt-test'
            && $request['store'] === false
            && $request['max_output_tokens'] === 128);
    }

    public function test_it_explains_a_missing_credit_balance_without_exposing_secrets(): void
    {
        config()->set('secretary.openai.api_key', 'never-print-this-secret');
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'error' => [
                    'code' => 'insufficient_quota',
                    'message' => 'You have no credits remaining.',
                ],
            ], 429),
        ]);

        $health = app(OpenAIHealthCheck::class)->run();

        $this->assertFalse($health['passed']);
        $this->assertStringContainsString('no available credits', $health['details']);
        $this->assertStringNotContainsString('never-print-this-secret', json_encode($health));
    }
}
