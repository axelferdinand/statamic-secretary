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

    public function requestCode(string $email, string $label, string $publicUrl): void
    {
        $email = mb_strtolower(trim($email));
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        $publicUrl = rtrim(trim($publicUrl), '/');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || mb_strlen($email) > 255
            || $label === ''
            || mb_strlen($label) > 120
            || ! $this->email->isPublicHttpsUrl($publicUrl)
            || ! $this->configuration->hasValidBaseUrl()
            || ! $this->configuration->pairingAvailable()) {
            throw new RelayDeliveryFailed('Secretary relay verification request is invalid.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post($this->configuration->pairingRequestEndpoint(), [
                    'version' => 1,
                    'email' => $email,
                    'label' => $label,
                ]);
        } catch (ConnectionException $exception) {
            throw new RelayDeliveryFailed('Secretary could not reach the shared-address relay.', previous: $exception);
        } catch (Throwable $exception) {
            throw new RelayDeliveryFailed('Secretary could not request a verification code.', previous: $exception);
        }

        $payload = $response->json();

        if (! $response->successful()
            || ! is_array($payload)
            || array_keys($payload) !== ['accepted', 'status']
            || ($payload['accepted'] ?? null) !== true
            || ($payload['status'] ?? null) !== 'verification_sent') {
            throw new RelayDeliveryFailed('The shared-address relay could not send the verification code.');
        }

        $this->configuration->store([
            ...$this->configuration->stored(),
            'pending_sender' => $email,
            'pending_public_url' => $publicUrl,
            'verification_requested_at' => now()->toIso8601String(),
        ]);
    }

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

        $stored = [
            ...$stored,
            'pending_code_fingerprint' => $codeFingerprint,
            'pending_claim_id' => $claimId,
            'pending_public_url' => $publicUrl,
        ];
        $this->configuration->store($stored);

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

        if (is_array($payload) && ($payload['status'] ?? null) === 'payment_required') {
            return $this->storePendingCheckout($payload, $stored);
        }

        return $this->storeConnectedResponse($payload, $stored);
    }

    /** @return array<string, mixed> */
    public function resumePending(): array
    {
        $stored = $this->configuration->stored();

        if (! $this->configuration->canResumePendingPairing()
            || ! $this->configuration->hasValidBaseUrl()) {
            throw new RelayDeliveryFailed('Secretary does not have a pending relay connection to finish.');
        }

        $publicUrl = rtrim((string) data_get($stored, 'pending_public_url'), '/');

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post($this->configuration->pairingStatusEndpoint(), [
                    'version' => 1,
                    'installation_id' => data_get($stored, 'pending_installation_id'),
                    'claim_id' => data_get($stored, 'pending_claim_id'),
                    'webhook_url' => $publicUrl.'/_secretary/webhooks/relay/inbound',
                ]);
        } catch (ConnectionException $exception) {
            throw new RelayDeliveryFailed('Secretary could not reach the shared-address relay.', previous: $exception);
        } catch (Throwable $exception) {
            throw new RelayDeliveryFailed('Secretary could not verify the relay checkout.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RelayDeliveryFailed('The shared-address relay could not verify the checkout yet.');
        }

        $payload = $response->json();

        if (is_array($payload) && ($payload['status'] ?? null) === 'payment_required') {
            return $this->storePendingCheckout($payload, $stored);
        }

        return $this->storeConnectedResponse($payload, $stored);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function storeConnectedResponse(mixed $payload, array $stored): array
    {
        $allowed = [
            'accepted',
            'status',
            'installation_id',
            'route_token',
            'signing_secret',
            'address',
            'route_address',
            'billing_status',
        ];

        if (! is_array($payload)
            || array_diff(array_keys($payload), $allowed) !== []
            || array_diff(array_diff($allowed, ['route_address', 'billing_status']), array_keys($payload)) !== []
            || ($payload['accepted'] ?? null) !== true
            || ! in_array($payload['status'] ?? null, ['paired', 'already_paired'], true)
            || ! is_string($payload['installation_id'])
            || preg_match('/^si_[a-z0-9_-]{20,125}$/D', $payload['installation_id']) !== 1
            || ! is_string($payload['route_token'])
            || preg_match('/^r[a-z0-9]{25}$/D', $payload['route_token']) !== 1
            || ! is_string($payload['signing_secret'])
            || strlen($this->decodeSecret($payload['signing_secret'])) < 32
            || ! is_string($payload['address'])
            || filter_var($payload['address'], FILTER_VALIDATE_EMAIL) === false
            || (isset($payload['billing_status'])
                && ! in_array($payload['billing_status'], [
                    'beta',
                    'complimentary',
                    'active',
                    'trialing',
                    'past_due',
                ], true))) {
            throw new RelayDeliveryFailed('The shared-address relay returned an invalid pairing response.');
        }

        $routeAddress = mb_strtolower((string) ($payload['route_address'] ?? $payload['address']));

        if (! $this->addressMatchesRoute($routeAddress, $payload['route_token'])
            || ! $this->addressUsesRouteDomain($payload['address'], $routeAddress)) {
            throw new RelayDeliveryFailed('The shared-address relay returned an invalid pairing response.');
        }

        $settings = [
            'enabled' => true,
            'installation_id' => $payload['installation_id'],
            'route_token' => $payload['route_token'],
            'signing_secret' => $payload['signing_secret'],
            'address' => mb_strtolower($payload['address']),
            'route_address' => $routeAddress,
            'sender' => data_get($stored, 'pending_sender'),
            'base_url' => $this->configuration->baseUrl(),
            'connected_at' => now()->toIso8601String(),
            'billing_status' => is_string($payload['billing_status'] ?? null)
                ? $payload['billing_status']
                : 'active',
        ];
        $this->configuration->store($settings);

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function storePendingCheckout(array $payload, array $stored): array
    {
        $allowed = [
            'accepted',
            'status',
            'installation_id',
            'billing_status',
            'checkout_url',
            'checkout_expires_at',
            'price',
        ];
        $parts = is_string($payload['checkout_url'] ?? null)
            ? parse_url($payload['checkout_url'])
            : false;
        $price = $payload['price'] ?? null;

        if (array_keys($payload) !== $allowed
            || ($payload['accepted'] ?? null) !== true
            || ! is_string($payload['installation_id'] ?? null)
            || preg_match('/^si_[a-z0-9_-]{20,125}$/D', $payload['installation_id']) !== 1
            || ! in_array($payload['billing_status'] ?? null, ['beta', 'pending', 'past_due'], true)
            || ! is_array($parts)
            || mb_strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || mb_strtolower((string) ($parts['host'] ?? '')) !== 'checkout.stripe.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! is_int($payload['checkout_expires_at'] ?? null)
            || $payload['checkout_expires_at'] <= now()->getTimestamp()
            || ! is_array($price)
            || $price !== ['amount' => 4900, 'currency' => 'usd', 'interval' => 'year']) {
            throw new RelayDeliveryFailed('The shared-address relay returned an invalid checkout response.');
        }

        $settings = [
            ...$stored,
            'enabled' => false,
            'pending_installation_id' => $payload['installation_id'],
            'billing_status' => 'pending',
            'checkout_url' => $payload['checkout_url'],
            'checkout_expires_at' => $payload['checkout_expires_at'],
            'price' => $price,
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

    private function addressUsesRouteDomain(string $address, string $routeAddress): bool
    {
        [, $addressDomain] = explode('@', mb_strtolower($address), 2);
        [, $routeDomain] = explode('@', mb_strtolower($routeAddress), 2);

        return hash_equals($routeDomain, $addressDomain);
    }
}
