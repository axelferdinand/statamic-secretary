<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Data\HttpResult;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundMessage;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayAuthenticationFailed;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRateLimited;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayTransientFailure;
use AxelFerdinand\StatamicSecretaryRelay\Security\BasicAuth;
use Closure;
use JsonException;
use Throwable;

final class HostedRelayApplication
{
    private readonly ?Closure $reporter;

    public function __construct(
        private readonly BasicAuth $postmarkAuth,
        private readonly PostmarkInboundAdapter $postmark,
        private readonly InboundRouter $inbound,
        private readonly ReplyService $replies,
        private readonly SelectionService $selections,
        private readonly PairingService $pairings,
        ?callable $reporter = null,
        private readonly int $maximumRequestBytes = 262144,
        private readonly ?RateLimiter $rateLimiter = null,
    ) {
        if ($maximumRequestBytes < 32768 || $maximumRequestBytes > 1048576) {
            throw new RelayRejected('Hosted relay request limit is invalid.');
        }

        $this->reporter = $reporter ? Closure::fromCallable($reporter) : null;
    }

    /** @param  array<string, string>  $headers */
    public function postmarkInbound(
        array $headers,
        string $body,
        string $clientIdentity = 'unknown',
    ): HttpResult {
        if ($limited = $this->rateLimit('postmark_source', $clientIdentity)) {
            return $limited;
        }

        try {
            $this->postmarkAuth->verify($headers);
        } catch (RelayAuthenticationFailed $exception) {
            $this->report($exception);

            return $this->json(401, ['accepted' => false, 'status' => 'unauthorized'], [
                'WWW-Authenticate' => 'Basic realm="Statamic Secretary relay"',
            ]);
        }

        if (! $this->jsonRequest($headers, $body)) {
            return $this->json(200, ['accepted' => false, 'status' => 'rejected']);
        }

        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($payload)) {
                throw new RelayRejected('Postmark inbound request is invalid.');
            }

            $message = $this->postmark->adapt($payload);
            $outcome = $this->inbound->route($message);

            return match ($outcome->status) {
                'forwarded', 'duplicate' => $this->json(200, [
                    'accepted' => true,
                    'status' => $outcome->status,
                ]),
                'processing' => $this->json(503, [
                    'accepted' => false,
                    'status' => 'processing',
                ], ['Retry-After' => '5']),
                'selection_required' => $this->selectionResult(
                    $message,
                    $outcome->candidateRouteTokens,
                ),
                default => throw new RelayRejected('Relay returned an unknown routing outcome.'),
            };
        } catch (RelayTransientFailure $exception) {
            $this->report($exception);

            return $this->json(503, ['accepted' => false, 'status' => 'temporary_failure'], [
                'Retry-After' => '30',
            ]);
        } catch (JsonException|RelayRejected $exception) {
            $this->report($exception);

            return $this->json(200, ['accepted' => false, 'status' => 'rejected']);
        } catch (Throwable $exception) {
            $this->report($exception);

            return $this->json(503, ['accepted' => false, 'status' => 'temporary_failure'], [
                'Retry-After' => '30',
            ]);
        }
    }

    /** @param  array<string, string>  $headers */
    public function reply(
        array $headers,
        string $method,
        string $path,
        string $body,
        string $clientIdentity = 'unknown',
    ): HttpResult {
        if (mb_strtoupper($method) !== 'POST' || '/'.ltrim($path, '/') !== '/v1/replies') {
            return $this->json(404, ['accepted' => false, 'status' => 'not_found']);
        }

        if (! $this->jsonRequest($headers, $body)) {
            return $this->json(415, ['accepted' => false, 'status' => 'invalid_request']);
        }

        if ($limited = $this->rateLimit('reply_source', $clientIdentity)) {
            return $limited;
        }

        try {
            $outcome = $this->replies->accept($headers, 'POST', '/v1/replies', $body);

            if ($outcome->providerMessageId === null) {
                return $this->json(503, ['accepted' => false, 'status' => 'processing'], [
                    'Retry-After' => '5',
                ]);
            }

            return $this->json(200, [
                'accepted' => true,
                'status' => $outcome->duplicate ? 'duplicate' : 'sent',
                'provider_message_id' => $outcome->providerMessageId,
            ]);
        } catch (RelayTransientFailure $exception) {
            $this->report($exception);

            return $this->json(503, ['accepted' => false, 'status' => 'temporary_failure'], [
                'Retry-After' => '30',
            ]);
        } catch (RelayRejected $exception) {
            $this->report($exception);

            return $this->json(422, ['accepted' => false, 'status' => 'rejected']);
        } catch (Throwable $exception) {
            $this->report($exception);

            return $this->json(503, ['accepted' => false, 'status' => 'temporary_failure'], [
                'Retry-After' => '30',
            ]);
        }
    }

    /** @param  array<string, string>  $headers */
    public function pairing(
        array $headers,
        string $body,
        string $clientIdentity = 'unknown',
    ): HttpResult {
        if (! $this->jsonRequest($headers, $body)) {
            return $this->json(415, ['accepted' => false, 'status' => 'invalid_request']);
        }

        if ($limited = $this->rateLimit('pairing_source', $clientIdentity)) {
            return $limited;
        }

        try {
            $outcome = $this->pairings->claim($body);

            return $this->json(
                $outcome->duplicate ? 200 : 201,
                $this->pairings->response($outcome),
            );
        } catch (RelayTransientFailure $exception) {
            $this->report($exception);

            return $this->json(503, ['accepted' => false, 'status' => 'temporary_failure'], [
                'Retry-After' => '30',
            ]);
        } catch (RelayRejected $exception) {
            $this->report($exception);

            return $this->json(422, ['accepted' => false, 'status' => 'rejected']);
        } catch (Throwable $exception) {
            $this->report($exception);

            return $this->json(503, ['accepted' => false, 'status' => 'temporary_failure'], [
                'Retry-After' => '30',
            ]);
        }
    }

    /** @param  array<string, string>  $headers */
    private function jsonRequest(array $headers, string $body): bool
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $contentType = mb_strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''), 2)[0]));

        return $contentType === 'application/json'
            && $body !== ''
            && strlen($body) <= $this->maximumRequestBytes;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function json(int $status, array $payload, array $headers = []): HttpResult
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            $this->report($exception);
            $body = '{"accepted":false,"status":"temporary_failure"}';
            $status = 503;
        }

        return new HttpResult($status, $body, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
            ...$headers,
        ]);
    }

    private function report(Throwable $exception): void
    {
        if ($this->reporter) {
            ($this->reporter)($exception);
        }
    }

    private function rateLimit(string $scope, string $clientIdentity): ?HttpResult
    {
        if (! $this->rateLimiter) {
            return null;
        }

        $clientIdentity = trim($clientIdentity);

        if ($clientIdentity === '' || strlen($clientIdentity) > 255) {
            $clientIdentity = 'unknown';
        }

        try {
            $decision = $this->rateLimiter->attempt($scope, $clientIdentity);
        } catch (Throwable $exception) {
            $this->report($exception);

            return $this->json(503, ['accepted' => false, 'status' => 'temporary_failure'], [
                'Retry-After' => '30',
            ]);
        }

        if ($decision->allowed) {
            return null;
        }

        $exception = new RelayRateLimited(
            $scope,
            $decision->retryAfter(time()),
        );
        $this->report($exception);

        return $this->json(429, ['accepted' => false, 'status' => 'rate_limited'], [
            'Retry-After' => (string) $exception->retryAfter,
            'X-RateLimit-Remaining' => '0',
        ]);
    }

    /** @param  array<int, string>  $routeTokens */
    private function selectionResult(
        InboundMessage $message,
        array $routeTokens,
    ): HttpResult {
        $outcome = $this->selections->notify($message, $routeTokens);

        if ($outcome->status === 'processing') {
            return $this->json(503, ['accepted' => false, 'status' => 'processing'], [
                'Retry-After' => '5',
            ]);
        }

        if (! in_array($outcome->status, ['sent', 'duplicate'], true)) {
            throw new RelayRejected('Relay returned an unknown selection outcome.');
        }

        return $this->json(200, [
            'accepted' => false,
            'status' => 'selection_required',
        ]);
    }
}
