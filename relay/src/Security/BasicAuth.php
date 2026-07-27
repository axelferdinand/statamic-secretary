<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Security;

use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayAuthenticationFailed;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;

final readonly class BasicAuth
{
    public function __construct(
        private string $username,
        private string $password,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $username) !== 1
            || strlen($password) < 32
            || strlen($password) > 256
            || preg_match('/[\r\n\0]/', $password) === 1) {
            throw new RelayRejected('Relay webhook authentication configuration is invalid.');
        }
    }

    /** @param  array<string, string>  $headers */
    public function verify(array $headers): void
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $authorization = trim((string) ($headers['authorization'] ?? ''));

        if (strncasecmp($authorization, 'Basic ', 6) !== 0) {
            throw new RelayAuthenticationFailed('Relay webhook authentication failed.');
        }

        $decoded = base64_decode(substr($authorization, 6), true);

        if (! is_string($decoded) || ! str_contains($decoded, ':')) {
            throw new RelayAuthenticationFailed('Relay webhook authentication failed.');
        }

        [$username, $password] = explode(':', $decoded, 2);
        $usernameMatches = hash_equals($this->username, $username);
        $passwordMatches = hash_equals($this->password, $password);

        if (! $usernameMatches || ! $passwordMatches) {
            throw new RelayAuthenticationFailed('Relay webhook authentication failed.');
        }
    }
}
