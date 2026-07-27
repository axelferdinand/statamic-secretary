<?php

namespace AxelFerdinand\StatamicSecretary\Relay;

use AxelFerdinand\StatamicSecretary\Exceptions\RelayRouteRotationFailed;
use Illuminate\Support\Facades\DB;

final readonly class RelayRouteRotation
{
    public function __construct(private RelayConfiguration $configuration) {}

    /** @return array{rotation_id: string, route_token: string, address: string, transition_expires_at: int, duplicate: bool} */
    public function install(
        string $routeToken,
        string $rotationId,
        int $transitionMinutes = 15,
    ): array {
        $routeToken = mb_strtolower(trim($routeToken));
        $rotationId = trim($rotationId);

        if (filled(config('secretary.relay.route_token'))) {
            throw new RelayRouteRotationFailed(
                'Remove SECRETARY_RELAY_ROUTE_TOKEN before using database-backed route rotation.',
            );
        }

        if (preg_match('/^r[a-z0-9]{25}$/D', $routeToken) !== 1
            || preg_match('/^rr_[A-Za-z0-9_-]{43}$/D', $rotationId) !== 1
            || $transitionMinutes < 5
            || $transitionMinutes > 60) {
            throw new RelayRouteRotationFailed('Relay route rotation input is invalid.');
        }

        return DB::transaction(function () use (
            $routeToken,
            $rotationId,
            $transitionMinutes,
        ): array {
            $stored = $this->configuration->stored();
            $currentRoute = trim((string) data_get($stored, 'route_token'));
            $lastRotationId = trim((string) data_get($stored, 'last_route_rotation_id'));

            if ($lastRotationId !== '' && hash_equals($lastRotationId, $rotationId)) {
                if (! hash_equals($currentRoute, $routeToken)) {
                    throw new RelayRouteRotationFailed(
                        'Rotation ID was already installed with a different route.',
                    );
                }

                return [
                    'rotation_id' => $rotationId,
                    'route_token' => $routeToken,
                    'address' => (string) data_get($stored, 'address'),
                    'transition_expires_at' => (int) data_get(
                        $stored,
                        'previous_route_accept_new_until',
                        0,
                    ),
                    'duplicate' => true,
                ];
            }

            $now = now()->getTimestamp();
            $previous = trim((string) data_get($stored, 'previous_route_token'));

            if (! $this->configuration->configured()
                || preg_match('/^r[a-z0-9]{25}$/D', $currentRoute) !== 1
                || ! hash_equals($this->configuration->routeToken(), $currentRoute)) {
                throw new RelayRouteRotationFailed(
                    'A connected relay with a database-backed route is required.',
                );
            }

            if (($previous !== ''
                    && (int) data_get($stored, 'previous_route_accept_new_until', 0) >= $now)
                || hash_equals($currentRoute, $routeToken)
                || in_array($routeToken, $this->configuration->retiredRouteTokens(), true)) {
                throw new RelayRouteRotationFailed(
                    'The route is already used or the previous rotation is still transitioning.',
                );
            }

            $address = $this->rotatedAddress(
                (string) data_get($stored, 'address'),
                $currentRoute,
                $routeToken,
            );
            $retired = [...$this->configuration->retiredRouteTokens(), $currentRoute];
            $retired = array_values(array_unique($retired));
            $expiresAt = $now + ($transitionMinutes * 60);
            $this->configuration->store([
                ...$stored,
                'route_token' => $routeToken,
                'address' => $address,
                'retired_route_tokens' => $retired,
                'previous_route_token' => $currentRoute,
                'previous_route_accept_new_until' => $expiresAt,
                'last_route_rotation_id' => $rotationId,
            ]);

            return [
                'rotation_id' => $rotationId,
                'route_token' => $routeToken,
                'address' => $address,
                'transition_expires_at' => $expiresAt,
                'duplicate' => false,
            ];
        });
    }

    private function rotatedAddress(
        string $address,
        string $currentRoute,
        string $newRoute,
    ): string {
        $address = mb_strtolower(trim($address));

        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new RelayRouteRotationFailed('Stored relay address is invalid.');
        }

        [$local, $domain] = explode('@', $address, 2);
        $suffix = '+'.$currentRoute;

        if (! str_ends_with($local, $suffix)) {
            throw new RelayRouteRotationFailed('Stored relay address does not match its route.');
        }

        return substr($local, 0, -strlen($currentRoute)).$newRoute.'@'.$domain;
    }
}
