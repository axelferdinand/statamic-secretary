<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Persistence;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\BillingStore;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\InstallationAdminStore;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\PairingStore;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\PostmarkPollStore;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\RateLimitStore;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\RelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SelectionStore;
use AxelFerdinand\StatamicSecretaryRelay\Data\BillingCheckout;
use AxelFerdinand\StatamicSecretaryRelay\Data\ClaimState;
use AxelFerdinand\StatamicSecretaryRelay\Data\ConversationRoute;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundDelivery;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Data\PairingDefinition;
use AxelFerdinand\StatamicSecretaryRelay\Data\PairingOutcome;
use AxelFerdinand\StatamicSecretaryRelay\Data\RateLimitDecision;
use AxelFerdinand\StatamicSecretaryRelay\Data\RouteRotation;
use AxelFerdinand\StatamicSecretaryRelay\Data\SecretRotation;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\PublicSiteAlias;
use AxelFerdinand\StatamicSecretaryRelay\Tokens;
use Closure;
use JsonException;
use PDO;
use Throwable;

final class SqliteRelayStore implements BillingStore, InstallationAdminStore, PairingStore, PostmarkPollStore, RateLimitStore, RelayStore, SelectionStore
{
    private const ENCRYPTION_KEY_BYTES = 32;

    private const OPENSSL_CIPHER = 'aes-256-gcm';

    private const OPENSSL_NONCE_BYTES = 12;

    private const OPENSSL_TAG_BYTES = 16;

    private const OPENSSL_PREFIX = 'o1:';

    private const LEGACY_SODIUM_NONCE_BYTES = 24;

    private readonly string $workerId;

    private readonly Closure $clock;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $encryptionKey,
        ?string $workerId = null,
        private readonly int $leaseSeconds = 300,
        ?callable $clock = null,
    ) {
        if (strlen($encryptionKey) !== self::ENCRYPTION_KEY_BYTES
            || $leaseSeconds < 30
            || $leaseSeconds > 900
            || ($workerId !== null && preg_match('/^[A-Za-z0-9_-]{22,128}$/D', $workerId) !== 1)) {
            throw new RelayRejected('SQLite relay store configuration is invalid.');
        }

        $this->workerId = $workerId ?? self::randomToken();
        $this->clock = $clock ? Closure::fromCallable($clock) : static fn (): int => time();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
    }

    public static function encryptionKeyFromBase64(string $encoded): string
    {
        $key = base64_decode(trim($encoded), true);

        if (! is_string($key) || strlen($key) !== self::ENCRYPTION_KEY_BYTES) {
            throw new RelayRejected('SQLite relay encryption key is invalid.');
        }

        return $key;
    }

    public function saveInstallation(Installation $installation): void
    {
        $this->immediate(fn () => $this->saveInstallationRecord($installation, true));
    }

    public function saveBillingCheckout(string $installationId, BillingCheckout $checkout): void
    {
        $this->immediate(function () use ($installationId, $checkout): void {
            $statement = $this->pdo->prepare(
                <<<'SQL'
                    UPDATE relay_installations
                    SET billing_status = 'pending',
                        checkout_id = :checkout_id,
                        checkout_url = :checkout_url,
                        checkout_expires_at = :checkout_expires_at,
                        updated_at = :updated_at
                    WHERE id = :id
                    SQL,
            );
            $statement->execute([
                'checkout_id' => $checkout->id,
                'checkout_url' => $checkout->url,
                'checkout_expires_at' => $checkout->expiresAt,
                'updated_at' => $this->now(),
                'id' => $installationId,
            ]);

            if ($statement->rowCount() !== 1) {
                throw new RelayRejected('Subscription checkout could not be saved.');
            }
        });
    }

    public function applyBillingEvent(
        string $eventId,
        ?string $installationId,
        ?string $subscriptionId,
        ?string $customerId,
        string $status,
        ?int $periodEnd,
    ): bool {
        return $this->immediate(function () use (
            $eventId,
            $installationId,
            $subscriptionId,
            $customerId,
            $status,
            $periodEnd,
        ): bool {
            $existingEvent = $this->pdo->prepare(
                'SELECT event_id FROM relay_billing_events WHERE event_id = :event_id LIMIT 1',
            );
            $existingEvent->execute(['event_id' => $eventId]);

            if ($existingEvent->fetchColumn() !== false) {
                return false;
            }

            $installation = $installationId !== null
                ? $this->installationById($installationId)
                : null;
            $subscriptionInstallation = $subscriptionId !== null
                ? $this->installation('stripe_subscription_id = :value', $subscriptionId)
                : null;

            if ($installation !== null
                && $subscriptionInstallation !== null
                && ! hash_equals($installation->id, $subscriptionInstallation->id)) {
                throw new RelayRejected('Stripe subscription does not match the installation.');
            }

            $installation ??= $subscriptionInstallation;

            if ($installation === null) {
                throw new RelayRejected('Stripe subscription installation was not found.');
            }

            if ($installation->stripeSubscriptionId !== null
                && $subscriptionId !== null
                && ! hash_equals($installation->stripeSubscriptionId, $subscriptionId)) {
                throw new RelayRejected('Stripe subscription identity changed unexpectedly.');
            }

            if ($installation->stripeCustomerId !== null
                && $customerId !== null
                && ! hash_equals($installation->stripeCustomerId, $customerId)) {
                throw new RelayRejected('Stripe customer identity changed unexpectedly.');
            }

            $now = $this->now();
            $update = $this->pdo->prepare(
                <<<'SQL'
                    UPDATE relay_installations
                    SET billing_status = :billing_status,
                        stripe_subscription_id = COALESCE(:stripe_subscription_id, stripe_subscription_id),
                        stripe_customer_id = COALESCE(:stripe_customer_id, stripe_customer_id),
                        billing_period_end = COALESCE(:billing_period_end, billing_period_end),
                        checkout_id = NULL,
                        checkout_url = NULL,
                        checkout_expires_at = NULL,
                        updated_at = :updated_at
                    WHERE id = :id
                    SQL,
            );
            $update->execute([
                'billing_status' => $status,
                'stripe_subscription_id' => $subscriptionId,
                'stripe_customer_id' => $customerId,
                'billing_period_end' => $periodEnd,
                'updated_at' => $now,
                'id' => $installation->id,
            ]);

            if ($update->rowCount() !== 1) {
                throw new RelayRejected('Stripe subscription status could not be saved.');
            }

            $insert = $this->pdo->prepare(
                <<<'SQL'
                    INSERT INTO relay_billing_events (
                        event_id, installation_id, status, created_at
                    ) VALUES (
                        :event_id, :installation_id, :status, :created_at
                    )
                    SQL,
            );
            $insert->execute([
                'event_id' => $eventId,
                'installation_id' => $installation->id,
                'status' => $status,
                'created_at' => $now,
            ]);

            return true;
        });
    }

    public function consumeRateLimit(
        string $scope,
        string $subject,
        int $limit,
        int $windowSeconds,
    ): RateLimitDecision {
        $subject = trim($subject);

        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $scope) !== 1
            || $subject === ''
            || strlen($subject) > 1024
            || $limit < 1
            || $limit > 100000
            || $windowSeconds < 10
            || $windowSeconds > 3600) {
            throw new RelayRejected('Relay rate-limit request is invalid.');
        }

        return $this->immediate(function () use (
            $scope,
            $subject,
            $limit,
            $windowSeconds,
        ): RateLimitDecision {
            $now = $this->now();
            $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
            $subjectHash = hash_hmac('sha256', $subject, $this->encryptionKey);
            $select = $this->pdo->prepare(
                <<<'SQL'
                    SELECT window_start, hits
                    FROM relay_rate_limits
                    WHERE scope = :scope
                      AND subject_hash = :subject_hash
                    LIMIT 1
                    SQL,
            );
            $select->execute([
                'scope' => $scope,
                'subject_hash' => $subjectHash,
            ]);
            $existing = $select->fetch();
            $hits = is_array($existing) && (int) $existing['window_start'] === $windowStart
                ? ((int) $existing['hits']) + 1
                : 1;
            $statement = $this->pdo->prepare(
                <<<'SQL'
                    INSERT INTO relay_rate_limits (
                        scope, subject_hash, window_start, hits, expires_at
                    ) VALUES (
                        :scope, :subject_hash, :window_start, :hits, :expires_at
                    )
                    ON CONFLICT(scope, subject_hash) DO UPDATE SET
                        window_start = excluded.window_start,
                        hits = excluded.hits,
                        expires_at = excluded.expires_at
                    SQL,
            );
            $statement->execute([
                'scope' => $scope,
                'subject_hash' => $subjectHash,
                'window_start' => $windowStart,
                'hits' => $hits,
                'expires_at' => $windowStart + $windowSeconds,
            ]);

            return new RateLimitDecision(
                $hits <= $limit,
                max(0, $limit - $hits),
                $windowStart + $windowSeconds,
            );
        });
    }

    public function prepareSecretRotation(string $installationId): SecretRotation
    {
        return $this->immediate(function () use ($installationId): SecretRotation {
            $installation = $this->installationById($installationId);

            if (! $installation) {
                throw new RelayRejected('Installation was not found.');
            }

            if ($installation->pendingSigningSecret !== null
                && $installation->pendingRotationId !== null) {
                return new SecretRotation(
                    $installation->id,
                    $installation->pendingRotationId,
                    $installation->pendingSigningSecret,
                    true,
                );
            }

            $now = $this->now();

            if ($installation->previousSigningSecret !== null
                && $installation->previousSecretExpiresAt !== null
                && $installation->previousSecretExpiresAt >= $now) {
                throw new RelayRejected('The previous signing-secret rotation is still in its grace period.');
            }

            $rotationId = Tokens::secretRotation();
            $secret = Tokens::signingSecret();
            $statement = $this->pdo->prepare(
                <<<'SQL'
                    UPDATE relay_installations
                    SET pending_signing_secret_ciphertext = :secret,
                        pending_rotation_id = :rotation_id,
                        rotation_started_at = :started_at,
                        updated_at = :updated_at
                    WHERE id = :id
                      AND pending_signing_secret_ciphertext IS NULL
                      AND pending_rotation_id IS NULL
                    SQL,
            );
            $statement->execute([
                'secret' => $this->encrypt($secret),
                'rotation_id' => $rotationId,
                'started_at' => $now,
                'updated_at' => $now,
                'id' => $installationId,
            ]);

            if ($statement->rowCount() !== 1) {
                throw new RelayRejected('Signing-secret rotation could not be prepared.');
            }

            return new SecretRotation($installationId, $rotationId, $secret);
        });
    }

    public function promoteSecretRotation(
        string $installationId,
        string $rotationId,
        int $graceSeconds,
    ): Installation {
        if (preg_match('/^sr_[A-Za-z0-9_-]{43}$/D', $rotationId) !== 1
            || $graceSeconds < 300
            || $graceSeconds > 3600) {
            throw new RelayRejected('Signing-secret promotion request is invalid.');
        }

        return $this->immediate(function () use (
            $installationId,
            $rotationId,
            $graceSeconds,
        ): Installation {
            $installation = $this->installationById($installationId);

            if (! $installation) {
                throw new RelayRejected('Installation was not found.');
            }

            if ($installation->pendingRotationId === null
                && $installation->pendingSigningSecret === null
                && $installation->lastRotationId !== null
                && hash_equals($installation->lastRotationId, $rotationId)) {
                return $installation;
            }

            if ($installation->pendingRotationId === null
                || $installation->pendingSigningSecret === null
                || ! hash_equals($installation->pendingRotationId, $rotationId)) {
                throw new RelayRejected('Signing-secret rotation does not match the pending rotation.');
            }

            $now = $this->now();
            $statement = $this->pdo->prepare(
                <<<'SQL'
                    UPDATE relay_installations
                    SET previous_signing_secret_ciphertext = signing_secret_ciphertext,
                        previous_secret_expires_at = :previous_expires_at,
                        signing_secret_ciphertext = pending_signing_secret_ciphertext,
                        pending_signing_secret_ciphertext = NULL,
                        pending_rotation_id = NULL,
                        last_rotation_id = :rotation_id,
                        rotation_started_at = NULL,
                        rotation_completed_at = :completed_at,
                        updated_at = :updated_at
                    WHERE id = :id
                      AND pending_rotation_id = :rotation_id
                      AND pending_signing_secret_ciphertext IS NOT NULL
                    SQL,
            );
            $statement->execute([
                'previous_expires_at' => $now + $graceSeconds,
                'rotation_id' => $rotationId,
                'completed_at' => $now,
                'updated_at' => $now,
                'id' => $installationId,
            ]);

            if ($statement->rowCount() !== 1) {
                throw new RelayRejected('Signing-secret rotation could not be promoted.');
            }

            $promoted = $this->installationById($installationId);

            if (! $promoted) {
                throw new RelayRejected('Promoted installation could not be loaded.');
            }

            return $promoted;
        });
    }

    public function prepareRouteRotation(string $installationId): RouteRotation
    {
        return $this->immediate(function () use ($installationId): RouteRotation {
            $installation = $this->installationById($installationId);

            if (! $installation) {
                throw new RelayRejected('Installation was not found.');
            }

            if ($installation->pendingRouteToken !== null
                && $installation->pendingRouteRotationId !== null) {
                return new RouteRotation(
                    $installation->id,
                    $installation->pendingRouteRotationId,
                    $installation->pendingRouteToken,
                    true,
                );
            }

            $now = $this->now();

            if ($installation->routeRotationAvailableAt !== null
                && $installation->routeRotationAvailableAt >= $now) {
                throw new RelayRejected('The previous route rotation is still in its transition period.');
            }

            $routeToken = '';

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $candidate = Tokens::route();
                $insert = $this->pdo->prepare(
                    <<<'SQL'
                        INSERT OR IGNORE INTO relay_installation_routes (
                            route_token, installation_id, status, created_at, retired_at
                        ) VALUES (
                            :route_token, :installation_id, 'pending', :created_at, NULL
                        )
                        SQL,
                );
                $insert->execute([
                    'route_token' => $candidate,
                    'installation_id' => $installationId,
                    'created_at' => $now,
                ]);

                if ($insert->rowCount() === 1) {
                    $routeToken = $candidate;

                    break;
                }
            }

            if ($routeToken === '') {
                throw new RelayRejected('A unique pending route could not be prepared.');
            }

            $rotationId = Tokens::routeRotation();
            $statement = $this->pdo->prepare(
                <<<'SQL'
                    UPDATE relay_installations
                    SET pending_route_token = :route_token,
                        pending_route_rotation_id = :rotation_id,
                        updated_at = :updated_at
                    WHERE id = :id
                      AND pending_route_token IS NULL
                      AND pending_route_rotation_id IS NULL
                    SQL,
            );
            $statement->execute([
                'route_token' => $routeToken,
                'rotation_id' => $rotationId,
                'updated_at' => $now,
                'id' => $installationId,
            ]);

            if ($statement->rowCount() !== 1) {
                throw new RelayRejected('Route rotation could not be prepared.');
            }

            return new RouteRotation($installationId, $rotationId, $routeToken);
        });
    }

    public function promoteRouteRotation(
        string $installationId,
        string $rotationId,
        int $transitionSeconds,
    ): Installation {
        if (preg_match('/^rr_[A-Za-z0-9_-]{43}$/D', $rotationId) !== 1
            || $transitionSeconds < 300
            || $transitionSeconds > 3600) {
            throw new RelayRejected('Route promotion request is invalid.');
        }

        return $this->immediate(function () use (
            $installationId,
            $rotationId,
            $transitionSeconds,
        ): Installation {
            $installation = $this->installationById($installationId);

            if (! $installation) {
                throw new RelayRejected('Installation was not found.');
            }

            if ($installation->pendingRouteToken === null
                && $installation->pendingRouteRotationId === null
                && $installation->lastRouteRotationId !== null
                && hash_equals($installation->lastRouteRotationId, $rotationId)) {
                return $installation;
            }

            if ($installation->pendingRouteToken === null
                || $installation->pendingRouteRotationId === null
                || ! hash_equals($installation->pendingRouteRotationId, $rotationId)) {
                throw new RelayRejected('Route rotation does not match the pending rotation.');
            }

            $now = $this->now();
            $retire = $this->pdo->prepare(
                <<<'SQL'
                    UPDATE relay_installation_routes
                    SET status = 'retired',
                        retired_at = :retired_at
                    WHERE route_token = :route_token
                      AND installation_id = :installation_id
                      AND status = 'current'
                    SQL,
            );
            $retire->execute([
                'retired_at' => $now,
                'route_token' => $installation->routeToken,
                'installation_id' => $installationId,
            ]);

            if ($retire->rowCount() !== 1) {
                throw new RelayRejected('Current route could not be retired.');
            }

            $promote = $this->pdo->prepare(
                <<<'SQL'
                    UPDATE relay_installation_routes
                    SET status = 'current',
                        retired_at = NULL
                    WHERE route_token = :route_token
                      AND installation_id = :installation_id
                      AND status = 'pending'
                    SQL,
            );
            $promote->execute([
                'route_token' => $installation->pendingRouteToken,
                'installation_id' => $installationId,
            ]);

            if ($promote->rowCount() !== 1) {
                throw new RelayRejected('Pending route could not be promoted.');
            }

            $statement = $this->pdo->prepare(
                <<<'SQL'
                    UPDATE relay_installations
                    SET route_token = pending_route_token,
                        pending_route_token = NULL,
                        pending_route_rotation_id = NULL,
                        last_route_rotation_id = :rotation_id,
                        route_rotation_available_at = :available_at,
                        updated_at = :updated_at
                    WHERE id = :id
                      AND pending_route_rotation_id = :rotation_id
                      AND pending_route_token IS NOT NULL
                    SQL,
            );
            $statement->execute([
                'rotation_id' => $rotationId,
                'available_at' => $now + $transitionSeconds,
                'updated_at' => $now,
                'id' => $installationId,
            ]);

            if ($statement->rowCount() !== 1) {
                throw new RelayRejected('Route rotation could not be promoted.');
            }

            $promoted = $this->installationById($installationId);

            if (! $promoted) {
                throw new RelayRejected('Promoted route could not be loaded.');
            }

            return $promoted;
        });
    }

    public function issuePairing(
        string $codeDigest,
        PairingDefinition $definition,
        int $expiresAt,
    ): void {
        $now = $this->now();

        if (preg_match('/^[a-f0-9]{64}$/D', $codeDigest) !== 1
            || $expiresAt < $now + 300
            || $expiresAt > $now + 3600) {
            throw new RelayRejected('Pairing issue request is invalid.');
        }

        try {
            $senders = json_encode(
                $definition->normalizedSenders(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new RelayRejected('Pairing senders could not be encoded.', previous: $exception);
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
                INSERT OR IGNORE INTO relay_pairing_codes (
                    code_digest, status, label, senders_json, expires_at, created_at
                ) VALUES (
                    :code_digest, 'issued', :label, :senders_json, :expires_at, :created_at
                )
                SQL,
        );
        $statement->execute([
            'code_digest' => $codeDigest,
            'label' => $definition->label,
            'senders_json' => $senders,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RelayRejected('Pairing code collision.');
        }
    }

    public function provisionPairing(
        string $codeDigest,
        string $claimFingerprint,
        string $webhookUrl,
        bool $requiresPayment = false,
    ): PairingOutcome {
        if (preg_match('/^[a-f0-9]{64}$/D', $codeDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $claimFingerprint) !== 1) {
            throw new RelayRejected('Pairing claim identity is invalid.');
        }

        return $this->immediate(function () use (
            $codeDigest,
            $claimFingerprint,
            $webhookUrl,
            $requiresPayment,
        ): PairingOutcome {
            $statement = $this->pdo->prepare(
                'SELECT * FROM relay_pairing_codes WHERE code_digest = :code_digest LIMIT 1',
            );
            $statement->execute(['code_digest' => $codeDigest]);
            $pairing = $statement->fetch();

            if (! is_array($pairing)) {
                throw new RelayRejected('Pairing code is invalid or expired.');
            }

            if ($pairing['status'] === 'complete') {
                if (! is_string($pairing['claim_fingerprint'])
                    || ! hash_equals($pairing['claim_fingerprint'], $claimFingerprint)
                    || ! is_string($pairing['installation_id'])) {
                    throw new RelayRejected('Pairing code has already been claimed.');
                }

                $installation = $this->installationById($pairing['installation_id']);

                if (! $installation) {
                    throw new RelayRejected('Paired installation could not be loaded.');
                }

                return new PairingOutcome($installation, true);
            }

            if ($pairing['status'] !== 'issued' || (int) $pairing['expires_at'] < $this->now()) {
                throw new RelayRejected('Pairing code is invalid or expired.');
            }

            try {
                $senders = json_decode((string) $pairing['senders_json'], true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RelayRejected('Pairing sender membership is invalid.', previous: $exception);
            }

            $definition = new PairingDefinition(
                (string) $pairing['label'],
                is_array($senders) ? array_values($senders) : [],
            );
            $normalizedSenders = $definition->normalizedSenders();
            $installation = $this->installationForPairing($webhookUrl, $normalizedSenders);
            $reconnected = $installation !== null;

            if (! $installation) {
                $routeToken = Tokens::route();
                $installation = new Installation(
                    Tokens::installation(),
                    $routeToken,
                    $webhookUrl,
                    Tokens::signingSecret(),
                    $normalizedSenders,
                    true,
                    $definition->label,
                    publicAlias: $this->availablePublicAlias($webhookUrl, $routeToken),
                    billingStatus: $requiresPayment ? 'pending' : 'beta',
                );
                $this->saveInstallationRecord($installation, false);
            }
            $complete = $this->pdo->prepare(
                <<<'SQL'
                    UPDATE relay_pairing_codes
                    SET status = 'complete',
                        claim_fingerprint = :claim_fingerprint,
                        installation_id = :installation_id,
                        claimed_at = :claimed_at
                    WHERE code_digest = :code_digest
                      AND status = 'issued'
                      AND expires_at >= :claimed_at
                    SQL,
            );
            $complete->execute([
                'claim_fingerprint' => $claimFingerprint,
                'installation_id' => $installation->id,
                'claimed_at' => $this->now(),
                'code_digest' => $codeDigest,
            ]);

            if ($complete->rowCount() !== 1) {
                throw new RelayRejected('Pairing code could not be claimed.');
            }

            return new PairingOutcome($installation, $reconnected);
        });
    }

    public function resumePairing(
        string $installationId,
        string $claimFingerprint,
    ): PairingOutcome {
        if (preg_match('/^si_[a-z0-9_-]{20,125}$/D', $installationId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $claimFingerprint) !== 1) {
            throw new RelayRejected('Pairing completion identity is invalid.');
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT installation_id
                FROM relay_pairing_codes
                WHERE status = 'complete'
                  AND installation_id = :installation_id
                  AND claim_fingerprint = :claim_fingerprint
                ORDER BY claimed_at DESC
                LIMIT 1
                SQL,
        );
        $statement->execute([
            'installation_id' => $installationId,
            'claim_fingerprint' => $claimFingerprint,
        ]);

        if ($statement->fetchColumn() !== $installationId) {
            throw new RelayRejected('Pairing completion could not be verified.');
        }

        $installation = $this->installationById($installationId);

        if (! $installation) {
            throw new RelayRejected('Paired installation could not be loaded.');
        }

        return new PairingOutcome($installation, true);
    }

    public function installationById(string $id): ?Installation
    {
        return $this->installation('id = :value', $id);
    }

    public function installationByRouteToken(string $routeToken): ?Installation
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT i.*
                FROM relay_installation_routes r
                INNER JOIN relay_installations i ON i.id = r.installation_id
                WHERE r.route_token = :route_token
                LIMIT 1
                SQL,
        );
        $statement->execute(['route_token' => $routeToken]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrateInstallation($row) : null;
    }

    public function installationByPublicAlias(string $publicAlias): ?Installation
    {
        $publicAlias = mb_strtolower(trim($publicAlias));

        if (! PublicSiteAlias::valid($publicAlias)) {
            return null;
        }

        return $this->installation(
            'public_alias = :value',
            $publicAlias,
        );
    }

    public function installationsForSender(string $sender): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT i.*
                FROM relay_installations i
                INNER JOIN relay_installation_senders s ON s.installation_id = i.id
                WHERE s.sender = :sender
                ORDER BY i.id
                SQL,
        );
        $statement->execute(['sender' => mb_strtolower(trim($sender))]);

        return array_map(
            fn (array $row): Installation => $this->hydrateInstallation($row),
            $statement->fetchAll(),
        );
    }

    public function conversationByToken(string $token): ?ConversationRoute
    {
        $statement = $this->pdo->prepare(
            'SELECT token, installation_id, route_token, sender
             FROM relay_conversations
             WHERE token = :token
             LIMIT 1',
        );
        $statement->execute(['token' => $token]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrateConversation($row) : null;
    }

    public function saveConversation(ConversationRoute $conversation): void
    {
        $this->immediate(function () use ($conversation): void {
            $now = $this->now();
            $insert = $this->pdo->prepare(
                <<<'SQL'
                    INSERT OR IGNORE INTO relay_conversations (
                        token, installation_id, route_token, sender, created_at, updated_at
                    ) VALUES (
                        :token, :installation_id, :route_token, :sender, :created_at, :updated_at
                    )
                    SQL,
            );
            $insert->execute([
                'token' => $conversation->token,
                'installation_id' => $conversation->installationId,
                'route_token' => $conversation->routeToken,
                'sender' => mb_strtolower(trim($conversation->sender)),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $existing = $this->conversationByToken($conversation->token);

            if (! $existing
                || ! hash_equals($existing->installationId, $conversation->installationId)
                || ! hash_equals($existing->routeToken, $conversation->routeToken)
                || ! hash_equals($existing->sender, mb_strtolower(trim($conversation->sender)))) {
                throw new RelayRejected('Conversation token collision.');
            }
        });
    }

    public function claimInbound(string $providerMessageId, string $installationId, string $fingerprint): ClaimState
    {
        return $this->claim(
            'relay_inbound_claims',
            'provider_message_id',
            $providerMessageId,
            $installationId,
            $fingerprint,
        );
    }

    public function claimPostmarkPoll(string $providerMessageId): ClaimState
    {
        if (preg_match('/^[A-Za-z0-9-]{1,255}$/D', $providerMessageId) !== 1) {
            throw new RelayRejected('Postmark poll message identity is invalid.');
        }

        return $this->immediate(function () use ($providerMessageId): ClaimState {
            $now = $this->now();
            $insert = $this->pdo->prepare(
                <<<'SQL'
                    INSERT OR IGNORE INTO relay_postmark_poll_claims (
                        provider_message_id, status, lease_owner, lease_expires_at,
                        created_at, updated_at
                    ) VALUES (
                        :provider_message_id, 'processing', :lease_owner, :lease_expires_at,
                        :created_at, :updated_at
                    )
                    SQL,
            );
            $insert->execute([
                'provider_message_id' => $providerMessageId,
                'lease_owner' => $this->workerId,
                'lease_expires_at' => $now + $this->leaseSeconds,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($insert->rowCount() === 1) {
                return ClaimState::New;
            }

            $select = $this->pdo->prepare(
                <<<'SQL'
                    SELECT status, lease_expires_at
                    FROM relay_postmark_poll_claims
                    WHERE provider_message_id = :provider_message_id
                    LIMIT 1
                    SQL,
            );
            $select->execute(['provider_message_id' => $providerMessageId]);
            $existing = $select->fetch();

            if (! is_array($existing)) {
                return ClaimState::Processing;
            }

            if ($existing['status'] === ClaimState::Complete->value) {
                return ClaimState::Complete;
            }

            if ((int) $existing['lease_expires_at'] >= $now) {
                return ClaimState::Processing;
            }

            $reclaim = $this->pdo->prepare(
                <<<'SQL'
                    UPDATE relay_postmark_poll_claims
                    SET lease_owner = :lease_owner,
                        lease_expires_at = :lease_expires_at,
                        updated_at = :updated_at
                    WHERE provider_message_id = :provider_message_id
                      AND status = 'processing'
                      AND lease_expires_at < :now
                    SQL,
            );
            $reclaim->execute([
                'lease_owner' => $this->workerId,
                'lease_expires_at' => $now + $this->leaseSeconds,
                'updated_at' => $now,
                'provider_message_id' => $providerMessageId,
                'now' => $now,
            ]);

            return $reclaim->rowCount() === 1 ? ClaimState::New : ClaimState::Processing;
        });
    }

    public function completePostmarkPoll(string $providerMessageId): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                UPDATE relay_postmark_poll_claims
                SET status = 'complete',
                    lease_owner = NULL,
                    lease_expires_at = NULL,
                    updated_at = :updated_at
                WHERE provider_message_id = :provider_message_id
                  AND status = 'processing'
                  AND lease_owner = :lease_owner
                SQL,
        );
        $statement->execute([
            'updated_at' => $this->now(),
            'provider_message_id' => $providerMessageId,
            'lease_owner' => $this->workerId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RelayRejected('Postmark poll claim lease is no longer owned by this worker.');
        }
    }

    public function releasePostmarkPoll(string $providerMessageId): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                DELETE FROM relay_postmark_poll_claims
                WHERE provider_message_id = :provider_message_id
                  AND status = 'processing'
                  AND lease_owner = :lease_owner
                SQL,
        );
        $statement->execute([
            'provider_message_id' => $providerMessageId,
            'lease_owner' => $this->workerId,
        ]);
    }

    public function completeInbound(InboundDelivery $delivery): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                UPDATE relay_inbound_claims
                SET status = 'complete',
                    lease_owner = NULL,
                    lease_expires_at = NULL,
                    sender = :sender,
                    route_token = :route_token,
                    conversation_token = :conversation_token,
                    updated_at = :updated_at
                WHERE provider_message_id = :provider_message_id
                  AND installation_id = :installation_id
                  AND status = 'processing'
                  AND lease_owner = :lease_owner
                SQL,
        );
        $statement->execute([
            'sender' => mb_strtolower(trim($delivery->sender)),
            'route_token' => $delivery->routeToken,
            'conversation_token' => $delivery->conversationToken,
            'updated_at' => $this->now(),
            'provider_message_id' => $delivery->providerMessageId,
            'installation_id' => $delivery->installationId,
            'lease_owner' => $this->workerId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RelayRejected('Inbound claim lease is no longer owned by this worker.');
        }
    }

    public function releaseInbound(string $providerMessageId, string $installationId): void
    {
        $this->release(
            'relay_inbound_claims',
            'provider_message_id',
            $providerMessageId,
            $installationId,
        );
    }

    public function inboundDelivery(string $providerMessageId): ?InboundDelivery
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT provider_message_id, installation_id, sender, route_token, conversation_token
                FROM relay_inbound_claims
                WHERE provider_message_id = :provider_message_id
                  AND status = 'complete'
                LIMIT 1
                SQL,
        );
        $statement->execute(['provider_message_id' => $providerMessageId]);
        $row = $statement->fetch();

        if (! is_array($row)
            || ! is_string($row['sender'])
            || ! is_string($row['route_token'])
            || ! is_string($row['conversation_token'])) {
            return null;
        }

        return new InboundDelivery(
            $row['provider_message_id'],
            $row['installation_id'],
            $row['sender'],
            $row['route_token'],
            $row['conversation_token'],
        );
    }

    public function consumeNonce(string $installationId, string $nonce, int $expiresAt): bool
    {
        return $this->immediate(function () use ($installationId, $nonce, $expiresAt): bool {
            $delete = $this->pdo->prepare(
                'DELETE FROM relay_nonces
                 WHERE installation_id = :installation_id
                   AND nonce = :nonce
                   AND expires_at < :now',
            );
            $delete->execute([
                'installation_id' => $installationId,
                'nonce' => $nonce,
                'now' => $this->now(),
            ]);
            $insert = $this->pdo->prepare(
                'INSERT OR IGNORE INTO relay_nonces (installation_id, nonce, expires_at)
                 VALUES (:installation_id, :nonce, :expires_at)',
            );
            $insert->execute([
                'installation_id' => $installationId,
                'nonce' => $nonce,
                'expires_at' => $expiresAt,
            ]);

            return $insert->rowCount() === 1;
        });
    }

    public function claimReply(string $idempotencyKey, string $installationId, string $fingerprint): ClaimState
    {
        return $this->claim(
            'relay_reply_claims',
            'idempotency_key',
            $idempotencyKey,
            $installationId,
            $fingerprint,
        );
    }

    public function completeReply(string $idempotencyKey, string $installationId, string $providerMessageId): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                UPDATE relay_reply_claims
                SET status = 'complete',
                    lease_owner = NULL,
                    lease_expires_at = NULL,
                    provider_message_id = :provider_message_id,
                    updated_at = :updated_at
                WHERE idempotency_key = :idempotency_key
                  AND installation_id = :installation_id
                  AND status = 'processing'
                  AND lease_owner = :lease_owner
                SQL,
        );
        $statement->execute([
            'provider_message_id' => $providerMessageId,
            'updated_at' => $this->now(),
            'idempotency_key' => $idempotencyKey,
            'installation_id' => $installationId,
            'lease_owner' => $this->workerId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RelayRejected('Reply claim lease is no longer owned by this worker.');
        }
    }

    public function releaseReply(string $idempotencyKey, string $installationId): void
    {
        $this->release('relay_reply_claims', 'idempotency_key', $idempotencyKey, $installationId);
    }

    public function completedReplyProviderId(string $idempotencyKey, string $installationId): ?string
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT provider_message_id
                FROM relay_reply_claims
                WHERE idempotency_key = :idempotency_key
                  AND installation_id = :installation_id
                  AND status = 'complete'
                LIMIT 1
                SQL,
        );
        $statement->execute([
            'idempotency_key' => $idempotencyKey,
            'installation_id' => $installationId,
        ]);
        $providerMessageId = $statement->fetchColumn();

        return is_string($providerMessageId) && $providerMessageId !== '' ? $providerMessageId : null;
    }

    public function claimSelection(string $providerMessageId, string $fingerprint): ClaimState
    {
        if ($providerMessageId === ''
            || mb_strlen($providerMessageId) > 255
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new RelayRejected('Selection claim identity is invalid.');
        }

        return $this->immediate(function () use ($providerMessageId, $fingerprint): ClaimState {
            $now = $this->now();
            $insert = $this->pdo->prepare(
                <<<'SQL'
                    INSERT OR IGNORE INTO relay_selection_claims (
                        provider_message_id, fingerprint, status, lease_owner,
                        lease_expires_at, created_at, updated_at
                    ) VALUES (
                        :provider_message_id, :fingerprint, 'processing', :lease_owner,
                        :lease_expires_at, :created_at, :updated_at
                    )
                    SQL,
            );
            $insert->execute([
                'provider_message_id' => $providerMessageId,
                'fingerprint' => $fingerprint,
                'lease_owner' => $this->workerId,
                'lease_expires_at' => $now + $this->leaseSeconds,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($insert->rowCount() === 1) {
                return ClaimState::New;
            }

            $select = $this->pdo->prepare(
                <<<'SQL'
                    SELECT fingerprint, status, lease_expires_at
                    FROM relay_selection_claims
                    WHERE provider_message_id = :provider_message_id
                    LIMIT 1
                    SQL,
            );
            $select->execute(['provider_message_id' => $providerMessageId]);
            $existing = $select->fetch();

            if (! is_array($existing) || ! hash_equals((string) $existing['fingerprint'], $fingerprint)) {
                return ClaimState::Conflict;
            }

            if ($existing['status'] === ClaimState::Complete->value) {
                return ClaimState::Complete;
            }

            if ((int) $existing['lease_expires_at'] >= $now) {
                return ClaimState::Processing;
            }

            $reclaim = $this->pdo->prepare(
                <<<'SQL'
                    UPDATE relay_selection_claims
                    SET lease_owner = :lease_owner,
                        lease_expires_at = :lease_expires_at,
                        updated_at = :updated_at
                    WHERE provider_message_id = :provider_message_id
                      AND fingerprint = :fingerprint
                      AND status = 'processing'
                      AND lease_expires_at < :now
                    SQL,
            );
            $reclaim->execute([
                'lease_owner' => $this->workerId,
                'lease_expires_at' => $now + $this->leaseSeconds,
                'updated_at' => $now,
                'provider_message_id' => $providerMessageId,
                'fingerprint' => $fingerprint,
                'now' => $now,
            ]);

            return $reclaim->rowCount() === 1 ? ClaimState::New : ClaimState::Processing;
        });
    }

    public function completeSelection(string $providerMessageId, string $providerReplyId): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                UPDATE relay_selection_claims
                SET status = 'complete',
                    lease_owner = NULL,
                    lease_expires_at = NULL,
                    provider_reply_id = :provider_reply_id,
                    updated_at = :updated_at
                WHERE provider_message_id = :provider_message_id
                  AND status = 'processing'
                  AND lease_owner = :lease_owner
                SQL,
        );
        $statement->execute([
            'provider_reply_id' => $providerReplyId,
            'updated_at' => $this->now(),
            'provider_message_id' => $providerMessageId,
            'lease_owner' => $this->workerId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RelayRejected('Selection claim lease is no longer owned by this worker.');
        }
    }

    public function releaseSelection(string $providerMessageId): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                DELETE FROM relay_selection_claims
                WHERE provider_message_id = :provider_message_id
                  AND status = 'processing'
                  AND lease_owner = :lease_owner
                SQL,
        );
        $statement->execute([
            'provider_message_id' => $providerMessageId,
            'lease_owner' => $this->workerId,
        ]);
    }

    public function completedSelectionProviderId(string $providerMessageId): ?string
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT provider_reply_id
                FROM relay_selection_claims
                WHERE provider_message_id = :provider_message_id
                  AND status = 'complete'
                LIMIT 1
                SQL,
        );
        $statement->execute(['provider_message_id' => $providerMessageId]);
        $providerReplyId = $statement->fetchColumn();

        return is_string($providerReplyId) && $providerReplyId !== '' ? $providerReplyId : null;
    }

    /** @return array{nonces: int, inbound: int, replies: int, selections: int, pairings: int, postmark_poll: int} */
    public function prune(int $completedBefore, ?int $now = null): array
    {
        $now ??= $this->now();

        return $this->immediate(function () use ($completedBefore, $now): array {
            $nonces = $this->delete(
                'DELETE FROM relay_nonces WHERE expires_at < :threshold',
                ['threshold' => $now],
            );
            $inbound = $this->delete(
                <<<'SQL'
                    DELETE FROM relay_inbound_claims
                    WHERE (status = 'complete' AND updated_at < :completed_before)
                       OR (status = 'processing' AND lease_expires_at < :now)
                    SQL,
                ['completed_before' => $completedBefore, 'now' => $now],
            );
            $replies = $this->delete(
                <<<'SQL'
                    DELETE FROM relay_reply_claims
                    WHERE (status = 'complete' AND updated_at < :completed_before)
                       OR (status = 'processing' AND lease_expires_at < :now)
                    SQL,
                ['completed_before' => $completedBefore, 'now' => $now],
            );
            $selections = $this->delete(
                <<<'SQL'
                    DELETE FROM relay_selection_claims
                    WHERE (status = 'complete' AND updated_at < :completed_before)
                       OR (status = 'processing' AND lease_expires_at < :now)
                    SQL,
                ['completed_before' => $completedBefore, 'now' => $now],
            );
            $pairings = $this->delete(
                <<<'SQL'
                    DELETE FROM relay_pairing_codes
                    WHERE (status = 'issued' AND expires_at < :now)
                       OR (status = 'complete' AND claimed_at < :completed_before)
                    SQL,
                ['completed_before' => $completedBefore, 'now' => $now],
            );
            $postmarkPoll = $this->delete(
                <<<'SQL'
                    DELETE FROM relay_postmark_poll_claims
                    WHERE (status = 'complete' AND updated_at < :completed_before)
                       OR (status = 'processing' AND lease_expires_at < :now)
                    SQL,
                ['completed_before' => $completedBefore, 'now' => $now],
            );
            $this->delete(
                <<<'SQL'
                    UPDATE relay_installations
                    SET previous_signing_secret_ciphertext = NULL,
                        previous_secret_expires_at = NULL
                    WHERE previous_secret_expires_at < :now
                    SQL,
                ['now' => $now],
            );
            $this->delete(
                'DELETE FROM relay_rate_limits WHERE expires_at < :now',
                ['now' => $now],
            );

            return [
                'nonces' => $nonces,
                'inbound' => $inbound,
                'replies' => $replies,
                'selections' => $selections,
                'pairings' => $pairings,
                'postmark_poll' => $postmarkPoll,
            ];
        });
    }

    private function saveInstallationRecord(Installation $installation, bool $allowUpdate): void
    {
        $now = $this->now();
        $update = $allowUpdate
            ? <<<'SQL'
                ON CONFLICT(id) DO UPDATE SET
                    route_token = excluded.route_token,
                    public_alias = excluded.public_alias,
                    webhook_url = excluded.webhook_url,
                    signing_secret_ciphertext = excluded.signing_secret_ciphertext,
                    pending_signing_secret_ciphertext = excluded.pending_signing_secret_ciphertext,
                    previous_signing_secret_ciphertext = excluded.previous_signing_secret_ciphertext,
                    previous_secret_expires_at = excluded.previous_secret_expires_at,
                    pending_rotation_id = excluded.pending_rotation_id,
                    last_rotation_id = excluded.last_rotation_id,
                    pending_route_token = excluded.pending_route_token,
                    pending_route_rotation_id = excluded.pending_route_rotation_id,
                    last_route_rotation_id = excluded.last_route_rotation_id,
                    route_rotation_available_at = excluded.route_rotation_available_at,
                    billing_status = excluded.billing_status,
                    stripe_customer_id = excluded.stripe_customer_id,
                    stripe_subscription_id = excluded.stripe_subscription_id,
                    billing_period_end = excluded.billing_period_end,
                    checkout_id = excluded.checkout_id,
                    checkout_url = excluded.checkout_url,
                    checkout_expires_at = excluded.checkout_expires_at,
                    active = excluded.active,
                    label = excluded.label,
                    updated_at = excluded.updated_at
                SQL
            : '';
        $statement = $this->pdo->prepare(
            <<<SQL
                INSERT INTO relay_installations (
                    id, route_token, webhook_url, signing_secret_ciphertext,
                    pending_signing_secret_ciphertext, previous_signing_secret_ciphertext,
                    previous_secret_expires_at, pending_rotation_id, last_rotation_id,
                    pending_route_token, pending_route_rotation_id,
                    last_route_rotation_id, route_rotation_available_at,
                    public_alias, billing_status, stripe_customer_id,
                    stripe_subscription_id, billing_period_end, checkout_id,
                    checkout_url, checkout_expires_at,
                    active, label, created_at, updated_at
                ) VALUES (
                    :id, :route_token, :webhook_url, :signing_secret_ciphertext,
                    :pending_signing_secret_ciphertext, :previous_signing_secret_ciphertext,
                    :previous_secret_expires_at, :pending_rotation_id, :last_rotation_id,
                    :pending_route_token, :pending_route_rotation_id,
                    :last_route_rotation_id, :route_rotation_available_at,
                    :public_alias, :billing_status, :stripe_customer_id,
                    :stripe_subscription_id, :billing_period_end, :checkout_id,
                    :checkout_url, :checkout_expires_at,
                    :active, :label, :created_at, :updated_at
                )
                {$update}
                SQL,
        );
        $statement->execute([
            'id' => $installation->id,
            'route_token' => $installation->routeToken,
            'webhook_url' => $installation->webhookUrl,
            'signing_secret_ciphertext' => $this->encrypt($installation->signingSecret),
            'pending_signing_secret_ciphertext' => $installation->pendingSigningSecret === null
                ? null
                : $this->encrypt($installation->pendingSigningSecret),
            'previous_signing_secret_ciphertext' => $installation->previousSigningSecret === null
                ? null
                : $this->encrypt($installation->previousSigningSecret),
            'previous_secret_expires_at' => $installation->previousSecretExpiresAt,
            'pending_rotation_id' => $installation->pendingRotationId,
            'last_rotation_id' => $installation->lastRotationId,
            'pending_route_token' => $installation->pendingRouteToken,
            'pending_route_rotation_id' => $installation->pendingRouteRotationId,
            'last_route_rotation_id' => $installation->lastRouteRotationId,
            'route_rotation_available_at' => $installation->routeRotationAvailableAt,
            'public_alias' => $installation->publicAlias,
            'billing_status' => $installation->billingStatus,
            'stripe_customer_id' => $installation->stripeCustomerId,
            'stripe_subscription_id' => $installation->stripeSubscriptionId,
            'billing_period_end' => $installation->billingPeriodEnd,
            'checkout_id' => $installation->checkoutId,
            'checkout_url' => $installation->checkoutUrl,
            'checkout_expires_at' => $installation->checkoutExpiresAt,
            'active' => $installation->active ? 1 : 0,
            'label' => $installation->label,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->ensureCurrentRoute($installation, $now);

        $delete = $this->pdo->prepare('DELETE FROM relay_installation_senders WHERE installation_id = :installation_id');
        $delete->execute(['installation_id' => $installation->id]);
        $insert = $this->pdo->prepare(
            'INSERT INTO relay_installation_senders (installation_id, sender, created_at)
             VALUES (:installation_id, :sender, :created_at)',
        );

        foreach ($installation->senders as $sender) {
            $insert->execute([
                'installation_id' => $installation->id,
                'sender' => mb_strtolower(trim($sender)),
                'created_at' => $now,
            ]);
        }
    }

    private function installation(string $where, string $value): ?Installation
    {
        $statement = $this->pdo->prepare("SELECT * FROM relay_installations WHERE {$where} LIMIT 1");
        $statement->execute(['value' => $value]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrateInstallation($row) : null;
    }

    /** @param  array<string, mixed>  $row */
    private function hydrateInstallation(array $row): Installation
    {
        $senderStatement = $this->pdo->prepare(
            'SELECT sender FROM relay_installation_senders WHERE installation_id = :installation_id ORDER BY sender',
        );
        $senderStatement->execute(['installation_id' => $row['id']]);
        $senders = $senderStatement->fetchAll(PDO::FETCH_COLUMN);

        return new Installation(
            (string) $row['id'],
            (string) $row['route_token'],
            (string) $row['webhook_url'],
            $this->decrypt((string) $row['signing_secret_ciphertext']),
            array_values(array_filter($senders, 'is_string')),
            (bool) $row['active'],
            is_string($row['label']) ? $row['label'] : null,
            is_string($row['pending_signing_secret_ciphertext'])
                ? $this->decrypt($row['pending_signing_secret_ciphertext'])
                : null,
            is_string($row['previous_signing_secret_ciphertext'])
                ? $this->decrypt($row['previous_signing_secret_ciphertext'])
                : null,
            isset($row['previous_secret_expires_at'])
                ? (int) $row['previous_secret_expires_at']
                : null,
            is_string($row['pending_rotation_id']) ? $row['pending_rotation_id'] : null,
            is_string($row['last_rotation_id']) ? $row['last_rotation_id'] : null,
            is_string($row['pending_route_token']) ? $row['pending_route_token'] : null,
            is_string($row['pending_route_rotation_id']) ? $row['pending_route_rotation_id'] : null,
            is_string($row['last_route_rotation_id']) ? $row['last_route_rotation_id'] : null,
            isset($row['route_rotation_available_at'])
                ? (int) $row['route_rotation_available_at']
                : null,
            is_string($row['public_alias'] ?? null) ? $row['public_alias'] : null,
            is_string($row['billing_status'] ?? null) ? $row['billing_status'] : 'beta',
            is_string($row['stripe_customer_id'] ?? null) ? $row['stripe_customer_id'] : null,
            is_string($row['stripe_subscription_id'] ?? null) ? $row['stripe_subscription_id'] : null,
            isset($row['billing_period_end']) ? (int) $row['billing_period_end'] : null,
            is_string($row['checkout_id'] ?? null) ? $row['checkout_id'] : null,
            is_string($row['checkout_url'] ?? null) ? $row['checkout_url'] : null,
            isset($row['checkout_expires_at']) ? (int) $row['checkout_expires_at'] : null,
        );
    }

    /** @return array<int, Installation> */
    public function installations(): array
    {
        $rows = $this->pdo->query(
            'SELECT * FROM relay_installations ORDER BY created_at, id',
        )->fetchAll();

        return array_map(
            fn (array $row): Installation => $this->hydrateInstallation($row),
            $rows,
        );
    }

    private function availablePublicAlias(string $webhookUrl, string $routeToken): string
    {
        $base = PublicSiteAlias::fromWebhookUrl($webhookUrl);
        $statement = $this->pdo->prepare(
            'SELECT id FROM relay_installations WHERE public_alias = :public_alias LIMIT 1',
        );
        $statement->execute(['public_alias' => $base]);

        if ($statement->fetchColumn() === false) {
            return $base;
        }

        $candidate = PublicSiteAlias::withRouteSuffix($base, $routeToken);
        $statement->execute(['public_alias' => $candidate]);

        if ($statement->fetchColumn() !== false) {
            throw new RelayRejected('A unique public email alias could not be allocated.');
        }

        return $candidate;
    }

    /** @param  array<int, string>  $senders */
    private function installationForPairing(string $webhookUrl, array $senders): ?Installation
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT *
                FROM relay_installations
                WHERE webhook_url = :webhook_url
                  AND active = 1
                ORDER BY created_at, id
                SQL,
        );
        $statement->execute(['webhook_url' => $webhookUrl]);
        $expected = array_values($senders);
        sort($expected);

        foreach ($statement->fetchAll() as $row) {
            $installation = $this->hydrateInstallation($row);
            $actual = $installation->senders;
            sort($actual);

            if ($actual === $expected) {
                return $installation;
            }
        }

        return null;
    }

    private function ensureCurrentRoute(Installation $installation, int $now): void
    {
        $insert = $this->pdo->prepare(
            <<<'SQL'
                INSERT OR IGNORE INTO relay_installation_routes (
                    route_token, installation_id, status, created_at, retired_at
                ) VALUES (
                    :route_token, :installation_id, 'current', :created_at, NULL
                )
                SQL,
        );
        $insert->execute([
            'route_token' => $installation->routeToken,
            'installation_id' => $installation->id,
            'created_at' => $now,
        ]);
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT installation_id, status
                FROM relay_installation_routes
                WHERE route_token = :route_token
                LIMIT 1
                SQL,
        );
        $statement->execute(['route_token' => $installation->routeToken]);
        $route = $statement->fetch();

        if (! is_array($route)
            || ! hash_equals((string) $route['installation_id'], $installation->id)
            || $route['status'] !== 'current') {
            throw new RelayRejected('Installation route mapping is invalid.');
        }
    }

    /** @param  array<string, mixed>  $row */
    private function hydrateConversation(array $row): ConversationRoute
    {
        return new ConversationRoute(
            (string) $row['token'],
            (string) $row['installation_id'],
            (string) $row['route_token'],
            (string) $row['sender'],
        );
    }

    private function claim(
        string $table,
        string $identityColumn,
        string $identity,
        string $installationId,
        string $fingerprint,
    ): ClaimState {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new RelayRejected('Relay claim fingerprint is invalid.');
        }

        return $this->immediate(function () use (
            $table,
            $identityColumn,
            $identity,
            $installationId,
            $fingerprint,
        ): ClaimState {
            $now = $this->now();
            $insert = $this->pdo->prepare(
                "INSERT OR IGNORE INTO {$table} (
                    {$identityColumn}, installation_id, fingerprint, status,
                    lease_owner, lease_expires_at, created_at, updated_at
                 ) VALUES (
                    :identity, :installation_id, :fingerprint, 'processing',
                    :lease_owner, :lease_expires_at, :created_at, :updated_at
                 )",
            );
            $insert->execute([
                'identity' => $identity,
                'installation_id' => $installationId,
                'fingerprint' => $fingerprint,
                'lease_owner' => $this->workerId,
                'lease_expires_at' => $now + $this->leaseSeconds,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($insert->rowCount() === 1) {
                return ClaimState::New;
            }

            $select = $this->pdo->prepare(
                "SELECT installation_id, fingerprint, status, lease_expires_at
                 FROM {$table}
                 WHERE {$identityColumn} = :identity
                 LIMIT 1",
            );
            $select->execute(['identity' => $identity]);
            $existing = $select->fetch();

            if (! is_array($existing)
                || ! hash_equals((string) $existing['installation_id'], $installationId)
                || ! hash_equals((string) $existing['fingerprint'], $fingerprint)) {
                return ClaimState::Conflict;
            }

            if ($existing['status'] === ClaimState::Complete->value) {
                return ClaimState::Complete;
            }

            if ((int) $existing['lease_expires_at'] >= $now) {
                return ClaimState::Processing;
            }

            $reclaim = $this->pdo->prepare(
                "UPDATE {$table}
                 SET lease_owner = :lease_owner,
                     lease_expires_at = :lease_expires_at,
                     updated_at = :updated_at
                 WHERE {$identityColumn} = :identity
                   AND installation_id = :installation_id
                   AND fingerprint = :fingerprint
                   AND status = 'processing'
                   AND lease_expires_at < :now",
            );
            $reclaim->execute([
                'lease_owner' => $this->workerId,
                'lease_expires_at' => $now + $this->leaseSeconds,
                'updated_at' => $now,
                'identity' => $identity,
                'installation_id' => $installationId,
                'fingerprint' => $fingerprint,
                'now' => $now,
            ]);

            return $reclaim->rowCount() === 1 ? ClaimState::New : ClaimState::Processing;
        });
    }

    private function release(
        string $table,
        string $identityColumn,
        string $identity,
        string $installationId,
    ): void {
        $statement = $this->pdo->prepare(
            "DELETE FROM {$table}
             WHERE {$identityColumn} = :identity
               AND installation_id = :installation_id
               AND status = 'processing'
               AND lease_owner = :lease_owner",
        );
        $statement->execute([
            'identity' => $identity,
            'installation_id' => $installationId,
            'lease_owner' => $this->workerId,
        ]);
    }

    private function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::OPENSSL_NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::OPENSSL_CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::OPENSSL_TAG_BYTES,
        );

        if (! is_string($ciphertext) || strlen($tag) !== self::OPENSSL_TAG_BYTES) {
            throw new RelayRejected('Installation secret could not be encrypted.');
        }

        return self::OPENSSL_PREFIX.base64_encode($nonce.$tag.$ciphertext);
    }

    private function decrypt(string $encoded): string
    {
        if (str_starts_with($encoded, self::OPENSSL_PREFIX)) {
            return $this->decryptOpenSsl(substr($encoded, strlen(self::OPENSSL_PREFIX)));
        }

        return $this->decryptLegacySodium($encoded);
    }

    private function decryptOpenSsl(string $encoded): string
    {
        $combined = base64_decode($encoded, true);
        $minimumLength = self::OPENSSL_NONCE_BYTES + self::OPENSSL_TAG_BYTES + 1;

        if (! is_string($combined) || strlen($combined) < $minimumLength) {
            throw new RelayRejected('Stored installation secret is invalid.');
        }

        $nonce = substr($combined, 0, self::OPENSSL_NONCE_BYTES);
        $tag = substr($combined, self::OPENSSL_NONCE_BYTES, self::OPENSSL_TAG_BYTES);
        $ciphertext = substr($combined, self::OPENSSL_NONCE_BYTES + self::OPENSSL_TAG_BYTES);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::OPENSSL_CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if (! is_string($plaintext) || strlen($plaintext) < 32) {
            throw new RelayRejected('Stored installation secret could not be decrypted.');
        }

        return $plaintext;
    }

    private function decryptLegacySodium(string $encoded): string
    {
        if (! function_exists('sodium_crypto_secretbox_open')) {
            throw new RelayRejected('Stored installation secret requires the legacy Sodium extension.');
        }

        $combined = base64_decode($encoded, true);

        if (! is_string($combined)
            || strlen($combined) <= self::LEGACY_SODIUM_NONCE_BYTES) {
            throw new RelayRejected('Stored installation secret is invalid.');
        }

        $nonce = substr($combined, 0, self::LEGACY_SODIUM_NONCE_BYTES);
        $ciphertext = substr($combined, self::LEGACY_SODIUM_NONCE_BYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->encryptionKey);

        if (! is_string($plaintext) || strlen($plaintext) < 32) {
            throw new RelayRejected('Stored installation secret could not be decrypted.');
        }

        return $plaintext;
    }

    /** @param  array<string, int>  $bindings */
    private function delete(string $sql, array $bindings): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->rowCount();
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    private function immediate(callable $callback): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        $active = true;

        try {
            $result = $callback();
            $this->pdo->exec('COMMIT');
            $active = false;

            return $result;
        } catch (Throwable $exception) {
            if ($active) {
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (Throwable) {
                    // Preserve the original failure if SQLite already ended the transaction.
                }
            }

            throw $exception;
        }
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
