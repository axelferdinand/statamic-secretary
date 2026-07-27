<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Observability;

use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayAuthenticationFailed;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRateLimited;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayTransientFailure;
use Throwable;

final class SecurityEventReporter
{
    /** @return array<string, string> */
    public static function context(Throwable $exception, ?int $timestamp = null): array
    {
        $record = [
            'event' => 'statamic_secretary_relay_error',
            'exception' => $exception::class,
            'category' => match (true) {
                $exception instanceof RelayAuthenticationFailed => 'authentication',
                $exception instanceof RelayRateLimited => 'rate_limit',
                $exception instanceof RelayTransientFailure => 'transient',
                $exception instanceof RelayRejected => 'rejected',
                default => 'unexpected',
            },
            'time' => gmdate('c', $timestamp ?? time()),
        ];

        if ($exception instanceof RelayRateLimited) {
            $record['scope'] = preg_match(
                '/^[a-z][a-z0-9_]{1,63}$/D',
                $exception->scope,
            ) === 1 ? $exception->scope : 'unknown';
        }

        return $record;
    }

    public static function report(Throwable $exception): void
    {
        error_log(json_encode(
            self::context($exception),
            JSON_UNESCAPED_SLASHES,
        ) ?: 'statamic_secretary_relay_error');
    }

    public static function reportBootFailure(Throwable $exception): void
    {
        error_log(json_encode([
            'event' => 'statamic_secretary_relay_boot_failure',
            'exception' => $exception::class,
            'category' => 'unexpected',
            'time' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES) ?: 'statamic_secretary_relay_boot_failure');
    }
}
