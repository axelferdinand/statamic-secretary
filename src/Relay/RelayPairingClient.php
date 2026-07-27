<?php

namespace AxelFerdinand\StatamicSecretary\Relay;

use AxelFerdinand\StatamicSecretary\Email\EmailConfiguration;
use AxelFerdinand\StatamicSecretary\Exceptions\RelayDeliveryFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class RelayPairingClient
{
    public function __construct(
        private readonly RelayConfiguration $configuration,
        private readonly EmailConfiguration $email,
    ) {}

    /** @return array<string, mixed> */
    public function connect(string $pairingCode, string $publicUrl): array
    {
        $pairingCode = trim($pairingCode);
        $publicUrl = rtrim(trim($publicUrl), '/');

        if (preg_match('/^pc_[A-Za-z0-9_-]{43}$/D', $pairingCode) !== 1
            || ! $this->email->isPublicHttpsUrl($publicUrl)
            || ! $this->configuration->hasValidBaseUrl()
            || ! $this->configuration->pairingAvailable()) {
            throw new RelayDeliveryFailed('Secretary relay pairing configuration is invalid.');
        }

        $codeFingerprint = hash('sha256', $pairingCode);
        $stored = $this->configuration->stored();
        $claimId = hash_equals(
            (string) data_get($stored, 'pending_code_fingerprint'),
            $codeFingerprint,
        ) ? (string) data_get($stored, 'pending_claim_id') : '';

        if (preg_match('/^pci_[A-Za-z0-9_-]{22,86}$/D', $claimId) !== 1) {
            $claimId = 'pci_'.Str::random(43);
        }

        $this->configuration->store([
            ...$stored,
            'pending_code_fingerprint' => $codeFingerprint,
            'pending_claim_id' => $claimId,
        ]);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post($this->configuration->pairingEndpoint(), [
                    'version' => 1,
                    'pairing_code' => $pairingCode,
                    'claim_id' => $claimId,
                    'webhook_url' => $publicUrl.'/_secretary/webhooks/relay/inbound',
                ]);
        } catch (ConnectionException $exception) {
            throw new RelayDeliveryFailed('Secretary could not reach the shared-address relay.', previous: $exception);
        } catch (Throwable $exception) {
            throw new RelayDeliveryFailed('Secretary could not complete relay pairing.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RelayDeliveryFailed('The shared-address relay rejected the pairing code.');
        }

        $payload = $response->json();
        $allowed = [
            'accepted',
            'status',
            'installation_id',
            'route_token',
            'signing_secret',
            'address',
        ];

        if (! is_array($payload)
            || array_diff(array_keys($payload), $allowed) !== []
            || array_diff($allowed, array_keys($payload)) !== []
            || ($payload['accepted'] ?? null) !== true
            || ! in_array($payload['status'] ?? null, ['paired', 'already_paired'], true)
            || ! is_string($payload['installation_id'])
            || preg_match('/^si_[a-z0-9_-]{20,125}$/D', $payload['installation_id']) !== 1
            || ! is_string($payload['route_token'])
            || preg_match('/^r[a-z0-9]{25}$/D', $payload['route_token']) !== 1
            || ! is_string($payload['signing_secret'])
            || strlen($this->decodeSecret($payload['signing_secret'])) < 32
            || ! is_string($payload['address'])
            || ! $this->addressMatchesRoute($payload['address'], $payload['route_token'])) {
            throw new RelayDeliveryFailed('The shared-address relay returned an invalid pairing response.');
        }

        $settings = [
            'enabled' => true,
            'installation_id' => $payload['installation_id'],
            'route_token' => $payload['route_token'],
            'signing_secret' => $payload['signing_secret'],
            'address' => mb_strtolower($payload['address']),
            'base_url' => $this->configuration->baseUrl(),
            'connected_at' => now()->toIso8601String(),
        ];
        $this->configuration->store($settings);

        return $settings;
    }

    private function decodeSecret(string $encoded): string
    {
        $padded = strtr($encoded, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);

        return is_string($decoded) ? $decoded : '';
    }

    private function addressMatchesRoute(string $address, string $routeToken): bool
    {
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        [$local] = explode('@', mb_strtolower($address), 2);

        return str_ends_with($local, '+'.$routeToken);
    }
}
