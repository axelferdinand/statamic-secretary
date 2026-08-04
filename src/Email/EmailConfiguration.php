<?php

namespace AxelFerdinand\StatamicSecretary\Email;

use AxelFerdinand\StatamicSecretary\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class EmailConfiguration
{
    private ?array $stored = null;

    public function tokenConfigured(): bool
    {
        return filled($this->postmarkToken());
    }

    public function postmarkToken(): string
    {
        $environment = trim((string) config('secretary.email.postmark.api_key'));

        return $environment !== ''
            ? $environment
            : trim((string) data_get($this->stored(), 'api_key'));
    }

    public function connected(): bool
    {
        return $this->tokenConfigured()
            && $this->emailAddressesAreUsable()
            && filled(data_get($this->stored(), 'connected_at'))
            && hash_equals(
                $this->webhookCredentialsFingerprint(),
                (string) data_get($this->stored(), 'webhook_credentials_fingerprint'),
            );
    }

    public function enabled(): bool
    {
        $explicit = config('secretary.email.enabled');

        if ($explicit !== null && $explicit !== '') {
            return filter_var($explicit, FILTER_VALIDATE_BOOL);
        }

        return $this->connected();
    }

    public function inboundAddress(): string
    {
        return trim((string) (config('secretary.email.address') ?: data_get($this->stored(), 'inbound_address')));
    }

    public function fromAddress(): string
    {
        return trim((string) (data_get($this->stored(), 'from_address') ?: config('secretary.email.from_address')));
    }

    public function fromName(): string
    {
        return trim((string) (data_get($this->stored(), 'from_name') ?: config('secretary.email.from_name', 'Secretary')));
    }

    public function mailer(): string
    {
        $configured = trim((string) config('secretary.email.mailer'));

        if ($configured !== '') {
            return $configured;
        }

        if ($this->connected()) {
            config()->set('mail.mailers.statamic_secretary_postmark.token', $this->postmarkToken());

            return 'statamic_secretary_postmark';
        }

        return (string) config('mail.default');
    }

    /** @return array<int, string> */
    public function allowedSenders(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $address): string => mb_strtolower(trim((string) $address)),
            (array) config('secretary.email.allowed_senders', []),
        )));
    }

    public function senderIsAllowed(string $email): bool
    {
        $allowed = $this->allowedSenders();

        return $allowed === [] || in_array(mb_strtolower(trim($email)), $allowed, true);
    }

    public function senderIsOwnAddress(string $email): bool
    {
        $fromAddress = mb_strtolower(trim($this->fromAddress()));

        return $fromAddress !== '' && hash_equals($fromAddress, mb_strtolower(trim($email)));
    }

    public function emailAddressesAreUsable(): bool
    {
        $inbound = $this->inboundAddress();
        $local = explode('@', $inbound, 2)[0] ?? '';

        return filter_var($inbound, FILTER_VALIDATE_EMAIL) !== false
            && filter_var($this->fromAddress(), FILTER_VALIDATE_EMAIL) !== false
            && ! str_contains($local, '+');
    }

    public function webhookUsername(): string
    {
        return trim((string) config('secretary.email.postmark.username')) ?: 'secretary';
    }

    public function webhookPassword(): string
    {
        $configured = trim((string) config('secretary.email.postmark.password'));

        if ($configured !== '') {
            return $configured;
        }

        $key = (string) config('app.key');

        return $key === '' ? '' : hash_hmac('sha256', 'statamic-secretary-postmark-webhook', $key);
    }

    public function webhookCredentialsFingerprint(): string
    {
        return hash('sha256', $this->webhookUsername()."\0".$this->webhookPassword());
    }

    public function webhookEndpoint(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        return $baseUrl.'/_secretary/webhooks/postmark/inbound';
    }

    public function authenticatedWebhookUrl(string $baseUrl): string
    {
        $endpoint = $this->webhookEndpoint($baseUrl);
        $parts = parse_url($endpoint);

        if (! is_array($parts) || blank($parts['scheme'] ?? null) || blank($parts['host'] ?? null)) {
            return '';
        }

        $host = (string) $parts['host'];
        $host = str_contains($host, ':') ? '['.$host.']' : $host;
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');

        return $parts['scheme'].'://'
            .rawurlencode($this->webhookUsername()).':'
            .rawurlencode($this->webhookPassword()).'@'
            .$host.$port.$path;
    }

    public function suggestedPublicUrl(): ?string
    {
        $url = rtrim((string) config('app.url'), '/');

        return $this->isPublicHttpsUrl($url) ? $url : null;
    }

    public function isPublicHttpsUrl(string $url): bool
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)
            || mb_strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || blank($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return false;
        }

        $host = mb_strtolower(rtrim((string) $parts['host'], '.'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return str_contains($host, '.');
    }

    /** @param array<string, mixed> $settings */
    public function store(array $settings): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'email'],
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
            if (! Schema::hasTable('secretary_settings')) {
                return $this->stored = [];
            }

            return $this->stored = (array) (Setting::query()->find('email')?->value ?? []);
        } catch (Throwable) {
            return $this->stored = [];
        }
    }

    /** @return array<string, mixed> */
    public function publicStatus(): array
    {
        return [
            'token_configured' => $this->tokenConfigured(),
            'token_source' => filled(config('secretary.email.postmark.api_key'))
                ? 'environment'
                : (filled(data_get($this->stored(), 'api_key')) ? 'control_panel' : null),
            'connected' => $this->connected(),
            'enabled' => $this->enabled(),
            'from_address' => $this->fromAddress(),
            'inbound_address' => $this->inboundAddress(),
            'server_name' => data_get($this->stored(), 'server_name'),
            'delivery_type' => data_get($this->stored(), 'delivery_type'),
            'webhook_endpoint' => data_get($this->stored(), 'webhook_endpoint'),
            'connected_at' => data_get($this->stored(), 'connected_at'),
            'suggested_public_url' => $this->suggestedPublicUrl(),
        ];
    }
}
