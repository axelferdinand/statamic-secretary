<?php

namespace AxelFerdinand\StatamicSecretary\OpenAI;

use AxelFerdinand\StatamicSecretary\Exceptions\OpenAIRequestFailed;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

final class OpenAIHealthCheck
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly OpenAIConfiguration $configuration,
    ) {}

    /** @return array{passed: bool, details: string, checked_at: string} */
    public function run(): array
    {
        if (! $this->configuration->configured()) {
            return [
                'passed' => false,
                'details' => 'Connect an OpenAI API key, then run the checks again.',
                'checked_at' => now()->toIso8601String(),
            ];
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim((string) config('secretary.openai.base_url'), '/'))
                ->withToken($this->configuration->apiKey())
                ->withHeaders(array_filter([
                    'OpenAI-Project' => config('secretary.openai.project'),
                ]))
                ->acceptJson()
                ->timeout(min(30, max(5, (int) config('secretary.openai.timeout', 120))))
                ->post('/responses', [
                    'model' => config('secretary.openai.model'),
                    'instructions' => 'This is a connectivity check. Reply with exactly OK.',
                    'input' => 'Reply with exactly OK.',
                    'max_output_tokens' => 128,
                    'store' => false,
                ])
                ->throw();

            $details = 'A live OpenAI request succeeded. The API key, model access, and credits are ready.';
            $this->configuration->recordHealth(true, $details);

            return $this->configuration->health() ?? [
                'passed' => true,
                'details' => $details,
                'checked_at' => now()->toIso8601String(),
            ];
        } catch (Throwable $exception) {
            $failure = OpenAIRequestFailed::from($exception);
            $this->configuration->recordHealth(false, $failure->healthDetails);

            return $this->configuration->health() ?? [
                'passed' => false,
                'details' => $failure->healthDetails,
                'checked_at' => now()->toIso8601String(),
            ];
        }
    }
}
