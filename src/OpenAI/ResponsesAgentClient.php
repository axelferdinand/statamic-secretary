<?php

namespace AxelFerdinand\StatamicSecretary\OpenAI;

use AxelFerdinand\StatamicSecretary\Contracts\AgentClient;
use AxelFerdinand\StatamicSecretary\Data\AgentRequest;
use AxelFerdinand\StatamicSecretary\Data\AgentResponse;
use AxelFerdinand\StatamicSecretary\Exceptions\OpenAIRequestFailed;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;
use Throwable;

final class ResponsesAgentClient implements AgentClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly OpenAIConfiguration $configuration,
    ) {}

    public function respond(AgentRequest $request): AgentResponse
    {
        $apiKey = $this->configuration->apiKey();

        if ($apiKey === '') {
            throw new RuntimeException('Secretary requires an OpenAI API key.');
        }

        $store = (bool) config('secretary.openai.store', true);

        $payload = array_filter([
            'model' => config('secretary.openai.model'),
            'instructions' => $request->instructions,
            'input' => $request->input,
            'tools' => $request->tools ?: null,
            'parallel_tool_calls' => false,
            'previous_response_id' => $request->previousResponseId,
            'safety_identifier' => $request->safetyIdentifier,
            'store' => $store,
            'include' => $store ? null : ['reasoning.encrypted_content'],
            'max_output_tokens' => max(256, (int) config('secretary.openai.max_output_tokens', 4096)),
            'reasoning' => [
                'effort' => config('secretary.openai.reasoning_effort', 'medium'),
            ],
        ], static fn (mixed $value): bool => $value !== null);

        try {
            $response = $this->http
                ->baseUrl(rtrim((string) config('secretary.openai.base_url'), '/'))
                ->withToken($apiKey)
                ->withHeaders(array_filter([
                    'OpenAI-Project' => config('secretary.openai.project'),
                ]))
                ->acceptJson()
                ->timeout((int) config('secretary.openai.timeout', 120))
                ->retry(2, 250, throw: false)
                ->post('/responses', $payload)
                ->throw()
                ->json();

            $this->configuration->recordHealth(
                true,
                'A live OpenAI request succeeded. The API key, model access, and credits are ready.',
            );
        } catch (Throwable $exception) {
            $failure = OpenAIRequestFailed::from($exception);
            $this->configuration->recordHealth(false, $failure->healthDetails);

            throw $failure;
        }

        if (! is_array($response) || ! isset($response['id'], $response['status'], $response['output'])) {
            throw new RuntimeException('OpenAI returned an invalid Responses API payload.');
        }

        if ($response['status'] !== 'completed') {
            throw new RuntimeException('OpenAI did not complete the response. Status: '.(string) $response['status'].'.');
        }

        return new AgentResponse(
            id: (string) $response['id'],
            status: (string) $response['status'],
            output: (array) $response['output'],
            text: $this->outputText((array) $response['output']),
            usage: (array) ($response['usage'] ?? []),
        );
    }

    /** @param  array<int, array<string, mixed>>  $output */
    private function outputText(array $output): string
    {
        $parts = [];

        foreach ($output as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ((array) ($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    $parts[] = $content['text'];
                }
            }
        }

        return trim(implode("\n", $parts));
    }
}
