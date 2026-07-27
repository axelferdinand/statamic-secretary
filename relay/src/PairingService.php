<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\PairingStore;
use AxelFerdinand\StatamicSecretaryRelay\Data\IssuedPairing;
use AxelFerdinand\StatamicSecretaryRelay\Data\PairingDefinition;
use AxelFerdinand\StatamicSecretaryRelay\Data\PairingOutcome;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Security\PublicHttpsUrl;
use JsonException;

final readonly class PairingService
{
    public function __construct(
        private PairingStore $store,
        private RelayAddress $address,
        private PublicHttpsUrl $urlPolicy,
    ) {}

    /** @param  array<int, string>  $senders */
    public function issue(string $label, array $senders, int $lifetimeMinutes = 30): IssuedPairing
    {
        if ($lifetimeMinutes < 5 || $lifetimeMinutes > 60) {
            throw new RelayRejected('Pairing lifetime is invalid.');
        }

        $definition = new PairingDefinition($label, $senders);
        $code = Tokens::pairingCode();
        $expiresAt = time() + ($lifetimeMinutes * 60);
        $this->store->issuePairing(hash('sha256', $code), $definition, $expiresAt);

        return new IssuedPairing($code, $expiresAt);
    }

    public function requestDefinition(string $body): PairingDefinition
    {
        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RelayRejected('Pairing-code request is invalid JSON.', previous: $exception);
        }

        $allowed = ['version', 'email', 'label'];

        if (! is_array($payload)
            || array_diff(array_keys($payload), $allowed) !== []
            || array_diff($allowed, array_keys($payload)) !== []
            || ($payload['version'] ?? null) !== 1
            || ! is_string($payload['email'])
            || ! is_string($payload['label'])) {
            throw new RelayRejected('Pairing-code request failed validation.');
        }

        $email = mb_strtolower(trim($payload['email']));
        $label = trim(preg_replace('/\s+/u', ' ', $payload['label']) ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || mb_strlen($email) > 255
            || $label === ''
            || mb_strlen($label) > 120) {
            throw new RelayRejected('Pairing-code request failed validation.');
        }

        return new PairingDefinition($label, [$email]);
    }

    public function claim(string $body): PairingOutcome
    {
        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RelayRejected('Pairing request is invalid JSON.', previous: $exception);
        }

        $allowed = ['version', 'pairing_code', 'claim_id', 'webhook_url'];

        if (! is_array($payload)
            || array_diff(array_keys($payload), $allowed) !== []
            || array_diff($allowed, array_keys($payload)) !== []
            || ($payload['version'] ?? null) !== 1
            || ! is_string($payload['pairing_code'])
            || preg_match('/^pc_[A-Za-z0-9_-]{43}$/D', $payload['pairing_code']) !== 1
            || ! is_string($payload['claim_id'])
            || preg_match('/^pci_[A-Za-z0-9_-]{22,86}$/D', $payload['claim_id']) !== 1
            || ! is_string($payload['webhook_url'])
            || mb_strlen($payload['webhook_url']) > 2048) {
            throw new RelayRejected('Pairing request failed validation.');
        }

        $this->urlPolicy->resolve($payload['webhook_url']);
        $fingerprint = hash('sha256', implode("\0", [
            $payload['claim_id'],
            $payload['webhook_url'],
        ]));

        return $this->store->provisionPairing(
            hash('sha256', $payload['pairing_code']),
            $fingerprint,
            $payload['webhook_url'],
        );
    }

    /** @return array<string, mixed> */
    public function response(PairingOutcome $outcome): array
    {
        $installation = $outcome->installation;

        return [
            'accepted' => true,
            'status' => $outcome->duplicate ? 'already_paired' : 'paired',
            'installation_id' => $installation->id,
            'route_token' => $installation->routeToken,
            'signing_secret' => rtrim(strtr(base64_encode($installation->signingSecret), '+/', '-_'), '='),
            'address' => $this->address->routeAddress($installation->routeToken),
        ];
    }
}
