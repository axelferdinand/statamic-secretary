<?php

namespace AxelFerdinand\StatamicSecretary\Relay;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Request;

final class RelaySignature
{
    public function __construct(private readonly RelayConfiguration $configuration) {}

    public function verify(Request $request): void
    {
        abort_unless($this->configuration->configured(), 503, 'Secretary relay credentials are not configured.');
        $installationId = trim((string) $request->headers->get('Secretary-Installation'));
        $timestamp = trim((string) $request->headers->get('Secretary-Timestamp'));
        $nonce = trim((string) $request->headers->get('Secretary-Nonce'));
        $contentDigest = mb_strtolower(trim((string) $request->headers->get('Secretary-Content-SHA256')));
        $signature = mb_strtolower(trim((string) $request->headers->get('Secretary-Signature')));

        abort_unless($installationId !== '' && hash_equals($this->configuration->installationId(), $installationId), 403, 'Relay installation does not match this site.');
        abort_unless(preg_match('/^[0-9]{10}$/D', $timestamp) === 1, 403, 'Relay timestamp is invalid.');
        abort_unless(abs(now()->getTimestamp() - (int) $timestamp) <= $this->configuration->maximumClockSkew(), 403, 'Relay request has expired.');
        abort_unless(preg_match('/^[A-Za-z0-9_-]{22,128}$/D', $nonce) === 1, 403, 'Relay nonce is invalid.');
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $contentDigest) === 1, 403, 'Relay content digest is invalid.');
        abort_unless(preg_match('/^v1=[a-f0-9]{64}$/D', $signature) === 1, 403, 'Relay signature is invalid.');
        $bodyDigest = hash('sha256', $request->getContent());
        abort_unless(hash_equals($bodyDigest, $contentDigest), 403, 'Relay body digest does not match.');
        $canonical = $this->canonical(
            $request->getMethod(),
            $request->getPathInfo(),
            $installationId,
            $timestamp,
            $nonce,
            $contentDigest,
        );
        $matches = false;

        foreach ($this->configuration->verificationSecrets() as $secret) {
            $expected = 'v1='.hash_hmac('sha256', $canonical, $secret);
            $matches = hash_equals($expected, $signature) || $matches;
        }

        abort_unless($matches, 403, 'Relay signature is invalid.');

        $cache = Cache::store($this->configuration->cacheStore());
        $nonceKey = 'statamic-secretary:relay:nonce:'.hash('sha256', $installationId."\0".$nonce);
        abort_unless(
            $cache->add($nonceKey, true, $this->configuration->maximumClockSkew() * 2),
            409,
            'Relay request has already been accepted.',
        );
    }

    /** @return array<string, string> */
    public function headers(string $method, string $path, string $body, ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp = $timestamp ?? now()->getTimestamp();
        $nonce = $nonce ?? Str::random(40);
        $digest = hash('sha256', $body);
        $installationId = $this->configuration->installationId();
        $signature = hash_hmac('sha256', $this->canonical(
            mb_strtoupper($method),
            $path,
            $installationId,
            (string) $timestamp,
            $nonce,
            $digest,
        ), $this->configuration->secret());

        return [
            'Secretary-Installation' => $installationId,
            'Secretary-Timestamp' => (string) $timestamp,
            'Secretary-Nonce' => $nonce,
            'Secretary-Content-SHA256' => $digest,
            'Secretary-Signature' => 'v1='.$signature,
        ];
    }

    private function canonical(
        string $method,
        string $path,
        string $installationId,
        string $timestamp,
        string $nonce,
        string $contentDigest,
    ): string {
        return implode("\n", [
            mb_strtoupper($method),
            '/'.ltrim($path, '/'),
            $installationId,
            $timestamp,
            $nonce,
            $contentDigest,
        ]);
    }
}
