<?php

namespace AxelFerdinand\StatamicSecretary\OpenAI;

use AxelFerdinand\StatamicSecretary\Database\SecretaryDatabase;
use AxelFerdinand\StatamicSecretary\Models\Setting;
use Throwable;

final class OpenAIConfiguration
{
    private ?array $stored = null;

    public function apiKey(): string
    {
        $environment = trim((string) config('secretary.openai.api_key'));

        return $environment !== ''
            ? $environment
            : trim((string) data_get($this->stored(), 'api_key'));
    }

    public function configured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function source(): ?string
    {
        if (filled(config('secretary.openai.api_key'))) {
            return 'environment';
        }

        return filled(data_get($this->stored(), 'api_key')) ? 'control_panel' : null;
    }

    public function storeApiKey(string $apiKey): void
    {
        $settings = [
            ...$this->stored(),
            'api_key' => trim($apiKey),
            'configured_at' => now()->toIso8601String(),
            'health' => null,
        ];

        Setting::query()->updateOrCreate(
            ['key' => 'openai'],
            ['value' => $settings],
        );

        $this->stored = $settings;
    }

    /** @return array{passed: bool, details: string, checked_at: string}|null */
    public function health(): ?array
    {
        $health = data_get($this->stored(), 'health');

        if (! is_array($health)
            || ! is_bool($health['passed'] ?? null)
            || ! is_string($health['details'] ?? null)
            || ! is_string($health['checked_at'] ?? null)
            || ! hash_equals((string) ($health['key_fingerprint'] ?? ''), $this->keyFingerprint())) {
            return null;
        }

        return [
            'passed' => $health['passed'],
            'details' => trim($health['details']),
            'checked_at' => $health['checked_at'],
        ];
    }

    public function recordHealth(bool $passed, string $details): void
    {
        if (! $this->configured()) {
            return;
        }

        $settings = [
            ...$this->stored(),
            'health' => [
                'passed' => $passed,
                'details' => trim($details),
                'checked_at' => now()->toIso8601String(),
                'key_fingerprint' => $this->keyFingerprint(),
            ],
        ];

        Setting::query()->updateOrCreate(
            ['key' => 'openai'],
            ['value' => $settings],
        );

        $this->stored = $settings;
    }

    private function keyFingerprint(): string
    {
        return hash('sha256', $this->apiKey());
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        try {
            if (! app(SecretaryDatabase::class)->schema()->hasTable('secretary_settings')) {
                return $this->stored = [];
            }

            return $this->stored = (array) (Setting::query()->find('openai')?->value ?? []);
        } catch (Throwable) {
            return $this->stored = [];
        }
    }
}
