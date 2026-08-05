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
        ];

        Setting::query()->updateOrCreate(
            ['key' => 'openai'],
            ['value' => $settings],
        );

        $this->stored = $settings;
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
