<?php

namespace AxelFerdinand\StatamicSecretary\Relay;

use AxelFerdinand\StatamicSecretary\Database\SecretaryDatabase;
use AxelFerdinand\StatamicSecretary\Models\Setting;
use Throwable;

final class RelayConfiguration
{
    private ?array $stored = null;

    public function enabled(): bool
    {
        $explicit = config('secretary.relay.enabled');

        if ($explicit !== null && $explicit !== '') {
            return filter_var($explicit, FILTER_VALIDATE_BOOL);
        }

        return filter_var(data_get($this->stored(), 'enabled', false), FILTER_VALIDATE_BOOL)
            && filled(data_get($this->stored(), 'connected_at'));
    }

    public function pairingAvailable(): bool
    {
        return filter_var(config('secretary.relay.pairing_enabled', false), FILTER_VALIDATE_BOOL)
            || $this->connected();
    }

    public function connected(): bool
    {
        return $this->enabled() && $this->configured() && $this->hasValidBaseUrl();
    }

    public function installationId(): string
    {
        return trim((string) (config('secretary.relay.installation_id')
            ?: data_get($this->stored(), 'installation_id')));
    }

    public function routeToken(): string
    {
        return trim((string) (config('secretary.relay.route_token')
            ?: data_get($this->stored(), 'route_token')));
    }

    /** @return array<int, string> */
    public function retiredRouteTokens(): array
    {
        $tokens = data_get($this->stored(), 'retired_route_tokens', []);

        if (! is_array($tokens)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $tokens,
            fn (mixed $token): bool => is_string($token)
                && preg_match('/^r[a-z0-9]{25}$/D', $token) === 1
                && ! hash_equals($this->routeToken(), $token),
        )));
    }

    public function acceptsRouteToken(
        string $routeToken,
        ?string $conversationToken,
        ?int $now = null,
    ): bool {
        $routeToken = trim($routeToken);

        if (preg_match('/^r[a-z0-9]{25}$/D', $routeToken) !== 1) {
            return false;
        }

        if (hash_equals($this->routeToken(), $routeToken)) {
            return true;
        }

        if (! in_array($routeToken, $this->retiredRouteTokens(), true)) {
            return false;
        }

        $now ??= now()->getTimestamp();
        $previous = trim((string) data_get($this->stored(), 'previous_route_token'));
        $acceptNewUntil = (int) data_get(
            $this->stored(),
            'previous_route_accept_new_until',
            0,
        );

        return ($previous !== '' && hash_equals($previous, $routeToken) && $acceptNewUntil >= $now)
            || (is_string($conversationToken)
                && preg_match('/^c[a-z0-9]{25}$/D', $conversationToken) === 1);
    }

    public function secret(): string
    {
        $encoded = trim((string) (config('secretary.relay.signing_secret')
            ?: data_get($this->stored(), 'signing_secret')));

        return $this->decodeSecret($encoded);
    }

    public function previousSecret(): string
    {
        return $this->decodeSecret(trim((string) data_get(
            $this->stored(),
            'previous_signing_secret',
        )));
    }

    /** @return array<int, string> */
    public function verificationSecrets(?int $now = null): array
    {
        $now ??= now()->getTimestamp();
        $secrets = [$this->secret()];
        $previous = $this->previousSecret();
        $expiresAt = (int) data_get($this->stored(), 'previous_secret_expires_at', 0);

        if (strlen($previous) >= 32 && $expiresAt >= $now) {
            $secrets[] = $previous;
        }

        return array_values(array_unique(array_filter(
            $secrets,
            static fn (string $secret): bool => strlen($secret) >= 32,
        )));
    }

    private function decodeSecret(string $encoded): string
    {
        if (preg_match('/^[a-f0-9]{64}$/iD', $encoded) === 1) {
            return (string) hex2bin($encoded);
        }

        $padded = strtr($encoded, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);

        return is_string($decoded) ? $decoded : '';
    }

    public function configured(): bool
    {
        return preg_match('/^si_[a-z0-9_-]{20,125}$/D', $this->installationId()) === 1
            && preg_match('/^r[a-z0-9]{25}$/D', $this->routeToken()) === 1
            && strlen($this->secret()) >= 32;
    }

    public function maximumClockSkew(): int
    {
        return min(900, max(30, (int) config('secretary.relay.max_clock_skew', 300)));
    }

    public function cacheStore(): ?string
    {
        $store = trim((string) config('secretary.relay.cache_store'));

        return $store !== '' ? $store : null;
    }

    public function baseUrl(): string
    {
        return rtrim(trim((string) (data_get($this->stored(), 'base_url')
            ?: config('secretary.relay.base_url'))), '/');
    }

    public function replyEndpoint(): string
    {
        return $this->baseUrl().'/v1/replies';
    }

    public function hasValidBaseUrl(): bool
    {
        $parts = parse_url($this->baseUrl());

        return is_array($parts)
            && mb_strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && filled($parts['host'] ?? null)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    public function pairingEndpoint(): string
    {
        return $this->baseUrl().'/v1/pairings/claim';
    }

    public function pairingRequestEndpoint(): string
    {
        return $this->baseUrl().'/v1/pairings/request';
    }

    public function address(): string
    {
        return trim((string) data_get($this->stored(), 'address'));
    }

    /** @param  array<string, mixed>  $settings */
    public function store(array $settings): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'relay'],
            ['value' => $settings],
        );

        $this->stored = $settings;
    }

    /** @return array<string, mixed> */
    public function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        try {
            if (! app(SecretaryDatabase::class)->schema()->hasTable('secretary_settings')) {
                return $this->stored = [];
            }

            return $this->stored = (array) (Setting::query()->find('relay')?->value ?? []);
        } catch (Throwable) {
            return $this->stored = [];
        }
    }

    /** @return array<string, mixed> */
    public function publicStatus(): array
    {
        return [
            'pairing_available' => $this->pairingAvailable(),
            'connected' => $this->connected(),
            'enabled' => $this->enabled(),
            'address' => $this->address(),
            'route_address' => data_get($this->stored(), 'route_address'),
            'sender' => data_get($this->stored(), 'sender'),
            'pending_sender' => data_get($this->stored(), 'pending_sender'),
            'pending_public_url' => data_get($this->stored(), 'pending_public_url'),
            'verification_requested_at' => data_get(
                $this->stored(),
                'verification_requested_at',
            ),
            'base_url' => $this->baseUrl(),
            'connected_at' => data_get($this->stored(), 'connected_at'),
            'rotation_grace_until' => data_get(
                $this->stored(),
                'previous_secret_expires_at',
            ),
            'route_transition_until' => data_get(
                $this->stored(),
                'previous_route_accept_new_until',
            ),
        ];
    }
}
