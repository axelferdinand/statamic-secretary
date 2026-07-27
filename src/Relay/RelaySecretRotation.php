<?php

namespace AxelFerdinand\StatamicSecretary\Relay;

use AxelFerdinand\StatamicSecretary\Exceptions\RelaySecretRotationFailed;
use Illuminate\Support\Facades\DB;

final readonly class RelaySecretRotation
{
    public function __construct(private RelayConfiguration $configuration) {}

    /** @return array{rotation_id: string, grace_expires_at: int, duplicate: bool} */
    public function install(
        string $encodedSecret,
        string $rotationId,
        int $graceMinutes = 15,
    ): array {
        $encodedSecret = trim($encodedSecret);
        $rotationId = trim($rotationId);
        $newSecret = $this->decodeSecret($encodedSecret);

        if (filled(config('secretary.relay.signing_secret'))) {
            throw new RelaySecretRotationFailed(
                'Remove SECRETARY_RELAY_SIGNING_SECRET before using database-backed rotation.',
            );
        }

        if (preg_match('/^sr_[A-Za-z0-9_-]{43}$/D', $rotationId) !== 1
            || strlen($newSecret) !== 32
            || $graceMinutes < 5
            || $graceMinutes > 60) {
            throw new RelaySecretRotationFailed('Relay signing-secret rotation input is invalid.');
        }

        return DB::transaction(function () use (
            $newSecret,
            $rotationId,
            $graceMinutes,
        ): array {
            $stored = $this->configuration->stored();
            $oldEncoded = trim((string) data_get($stored, 'signing_secret'));
            $oldSecret = $this->decodeSecret($oldEncoded);
            $normalizedNew = $this->encodeSecret($newSecret);
            $lastRotationId = trim((string) data_get($stored, 'last_rotation_id'));

            if ($lastRotationId !== '' && hash_equals($lastRotationId, $rotationId)) {
                if (! hash_equals($oldSecret, $newSecret)) {
                    throw new RelaySecretRotationFailed(
                        'Rotation ID was already installed with a different signing secret.',
                    );
                }

                return [
                    'rotation_id' => $rotationId,
                    'grace_expires_at' => (int) data_get(
                        $stored,
                        'previous_secret_expires_at',
                        0,
                    ),
                    'duplicate' => true,
                ];
            }

            $now = now()->getTimestamp();

            if (! $this->configuration->configured()
                || strlen($oldSecret) < 32
                || ! hash_equals($this->configuration->secret(), $oldSecret)) {
                throw new RelaySecretRotationFailed(
                    'A connected relay with a database-backed signing secret is required.',
                );
            }

            if (strlen($this->configuration->previousSecret()) >= 32
                && (int) data_get($stored, 'previous_secret_expires_at', 0) >= $now) {
                throw new RelaySecretRotationFailed(
                    'The previous signing-secret rotation is still in its grace period.',
                );
            }

            $expiresAt = $now + ($graceMinutes * 60);
            $this->configuration->store([
                ...$stored,
                'signing_secret' => $normalizedNew,
                'previous_signing_secret' => $this->encodeSecret($oldSecret),
                'previous_secret_expires_at' => $expiresAt,
                'last_rotation_id' => $rotationId,
            ]);

            return [
                'rotation_id' => $rotationId,
                'grace_expires_at' => $expiresAt,
                'duplicate' => false,
            ];
        });
    }

    private function decodeSecret(string $encoded): string
    {
        if (preg_match('/^[a-f0-9]{64}$/iD', $encoded) === 1) {
            return (string) hex2bin($encoded);
        }

        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $encoded) !== 1) {
            return '';
        }

        $padded = strtr($encoded, '-_', '+/').'=';
        $decoded = base64_decode($padded, true);

        return is_string($decoded) ? $decoded : '';
    }

    private function encodeSecret(string $secret): string
    {
        return rtrim(strtr(base64_encode($secret), '+/', '-_'), '=');
    }
}
