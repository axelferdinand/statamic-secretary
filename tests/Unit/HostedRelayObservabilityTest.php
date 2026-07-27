<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayAuthenticationFailed;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRateLimited;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayTransientFailure;
use AxelFerdinand\StatamicSecretaryRelay\Observability\SecurityEventReporter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HostedRelayObservabilityTest extends TestCase
{
    public function test_security_records_classify_failures_without_copying_exception_messages(): void
    {
        $secret = 'never-log-this-secret-value';
        $cases = [
            [new RelayAuthenticationFailed($secret), 'authentication'],
            [new RelayRateLimited('pairing_source', 30), 'rate_limit'],
            [new RelayTransientFailure($secret), 'transient'],
            [new RelayRejected($secret), 'rejected'],
            [new RuntimeException($secret), 'unexpected'],
        ];

        foreach ($cases as [$exception, $category]) {
            $record = SecurityEventReporter::context(
                $exception,
                1_800_000_000,
            );
            $encoded = json_encode(
                $record,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );

            $this->assertSame(
                'statamic_secretary_relay_error',
                $record['event'],
            );
            $this->assertSame($category, $record['category']);
            $this->assertSame($exception::class, $record['exception']);
            $this->assertSame('2027-01-15T08:00:00+00:00', $record['time']);
            $this->assertStringNotContainsString($secret, $encoded);
            $this->assertArrayNotHasKey('message', $record);
        }

        $rateLimited = SecurityEventReporter::context(
            new RelayRateLimited('pairing_source', 30),
            1_800_000_000,
        );
        $this->assertSame('pairing_source', $rateLimited['scope']);
        $this->assertSame(
            'unknown',
            SecurityEventReporter::context(
                new RelayRateLimited("unsafe\nscope", 30),
                1_800_000_000,
            )['scope'],
        );
    }
}
