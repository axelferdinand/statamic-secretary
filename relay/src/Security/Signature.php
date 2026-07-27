<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Security;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\RelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;

final class Signature
{
    /** @return array<string, string> */
    public static function headers(
        Installation $installation,
        string $method,
        string $path,
        string $body,
        ?int $timestamp = null,
        ?string $nonce = null,
    ): array {
        $timestamp ??= time();
        $nonce ??= rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $digest = hash('sha256', $body);
        $signature = hash_hmac('sha256', self::canonical(
            $method,
            $path,
            $installation->id,
            (string) $timestamp,
            $nonce,
            $digest,
        ), $installation->signingSecret);

        return [
            'Secretary-Installation' => $installation->id,
            'Secretary-Timestamp' => (string) $timestamp,
            'Secretary-Nonce' => $nonce,
            'Secretary-Content-SHA256' => $digest,
            'Secretary-Signature' => 'v1='.$signature,
        ];
    }

    /** @param  array<string, string>  $headers */
    public static function verify(
        Installation $installation,
        RelayStore $store,
        array $headers,
        string $method,
        string $path,
        string $body,
        ?int $now = null,
        int $maximumClockSkew = 300,
    ): void {
        $now ??= time();
        $maximumClockSkew = min(900, max(30, $maximumClockSkew));
        $headers = array_change_key_case($headers, CASE_LOWER);
        $installationId = trim((string) ($headers['secretary-installation'] ?? ''));
        $timestamp = trim((string) ($headers['secretary-timestamp'] ?? ''));
        $nonce = trim((string) ($headers['secretary-nonce'] ?? ''));
        $digest = mb_strtolower(trim((string) ($headers['secretary-content-sha256'] ?? '')));
        $signature = mb_strtolower(trim((string) ($headers['secretary-signature'] ?? '')));

        if ($installationId === '' || ! hash_equals($installation->id, $installationId)
            || preg_match('/^[0-9]{10}$/D', $timestamp) !== 1
            || abs($now - (int) $timestamp) > $maximumClockSkew
            || preg_match('/^[A-Za-z0-9_-]{22,128}$/D', $nonce) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || preg_match('/^v1=[a-f0-9]{64}$/D', $signature) !== 1
            || ! hash_equals(hash('sha256', $body), $digest)) {
            throw new RelayRejected('Relay signature is invalid.');
        }

        $canonical = self::canonical(
            $method,
            $path,
            $installationId,
            $timestamp,
            $nonce,
            $digest,
        );
        $matches = false;

        foreach ($installation->acceptedSigningSecrets($now) as $secret) {
            $expected = 'v1='.hash_hmac('sha256', $canonical, $secret);
            $matches = hash_equals($expected, $signature) || $matches;
        }

        if (! $matches) {
            throw new RelayRejected('Relay signature is invalid.');
        }

        if (! $store->consumeNonce($installationId, $nonce, max($now + 1, (int) $timestamp + $maximumClockSkew))) {
            throw new RelayRejected('Relay nonce has already been used.');
        }
    }

    private static function canonical(
        string $method,
        string $path,
        string $installationId,
        string $timestamp,
        string $nonce,
        string $digest,
    ): string {
        return implode("\n", [
            mb_strtoupper($method),
            '/'.ltrim($path, '/'),
            $installationId,
            $timestamp,
            $nonce,
            $digest,
        ]);
    }
}
