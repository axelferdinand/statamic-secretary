<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use AxelFerdinand\StatamicSecretaryRelay\PublicSiteAlias;

final readonly class Installation
{
    /** @param  array<int, string>  $senders */
    public function __construct(
        public string $id,
        public string $routeToken,
        public string $webhookUrl,
        public string $signingSecret,
        public array $senders,
        public bool $active = true,
        public ?string $label = null,
        public ?string $pendingSigningSecret = null,
        public ?string $previousSigningSecret = null,
        public ?int $previousSecretExpiresAt = null,
        public ?string $pendingRotationId = null,
        public ?string $lastRotationId = null,
        public ?string $pendingRouteToken = null,
        public ?string $pendingRouteRotationId = null,
        public ?string $lastRouteRotationId = null,
        public ?int $routeRotationAvailableAt = null,
        public ?string $publicAlias = null,
        public string $billingStatus = 'beta',
        public ?string $stripeCustomerId = null,
        public ?string $stripeSubscriptionId = null,
        public ?int $billingPeriodEnd = null,
        public ?string $checkoutId = null,
        public ?string $checkoutUrl = null,
        public ?int $checkoutExpiresAt = null,
    ) {
        if (preg_match('/^si_[a-z0-9_-]{20,125}$/D', $id) !== 1
            || preg_match('/^r[a-z0-9]{25}$/D', $routeToken) !== 1
            || strlen($signingSecret) < 32
            || ! self::validWebhook($webhookUrl)
            || count($senders) > 500
            || ($label !== null && ($label === '' || mb_strlen($label) > 120))
            || (($pendingSigningSecret === null) !== ($pendingRotationId === null))
            || ($pendingSigningSecret !== null && strlen($pendingSigningSecret) < 32)
            || ($pendingRotationId !== null && ! self::validRotationId($pendingRotationId))
            || (($previousSigningSecret === null) !== ($previousSecretExpiresAt === null))
            || ($previousSigningSecret !== null && strlen($previousSigningSecret) < 32)
            || ($previousSecretExpiresAt !== null && $previousSecretExpiresAt < 1)
            || ($lastRotationId !== null && ! self::validRotationId($lastRotationId))
            || (($pendingRouteToken === null) !== ($pendingRouteRotationId === null))
            || ($pendingRouteToken !== null
                && (preg_match('/^r[a-z0-9]{25}$/D', $pendingRouteToken) !== 1
                    || hash_equals($routeToken, $pendingRouteToken)))
            || ($pendingRouteRotationId !== null
                && ! self::validRouteRotationId($pendingRouteRotationId))
            || ($lastRouteRotationId !== null
                && ! self::validRouteRotationId($lastRouteRotationId))
            || ($routeRotationAvailableAt !== null && $routeRotationAvailableAt < 1)
            || ($publicAlias !== null && ! PublicSiteAlias::valid($publicAlias))
            || ! in_array($billingStatus, [
                'beta',
                'complimentary',
                'pending',
                'active',
                'trialing',
                'past_due',
                'canceled',
                'unpaid',
                'incomplete',
                'incomplete_expired',
                'paused',
            ], true)
            || ($stripeCustomerId !== null
                && preg_match('/^cus_[A-Za-z0-9]+$/D', $stripeCustomerId) !== 1)
            || ($stripeSubscriptionId !== null
                && preg_match('/^sub_[A-Za-z0-9]+$/D', $stripeSubscriptionId) !== 1)
            || ($billingPeriodEnd !== null && $billingPeriodEnd < 1)
            || (($checkoutId === null) !== ($checkoutUrl === null))
            || (($checkoutId === null) !== ($checkoutExpiresAt === null))
            || ($checkoutId !== null
                && preg_match('/^cs_(?:test|live)_[A-Za-z0-9]+$/D', $checkoutId) !== 1)
            || ($checkoutUrl !== null && ! self::validCheckoutUrl($checkoutUrl))
            || ($checkoutExpiresAt !== null && $checkoutExpiresAt < 1)
            || ! self::validSenders($senders)) {
            throw new RelayRejected('Installation configuration is invalid.');
        }
    }

    public function allowsSender(string $sender): bool
    {
        $sender = mb_strtolower(trim($sender));

        return in_array($sender, array_map(
            static fn (string $allowed): string => mb_strtolower(trim($allowed)),
            $this->senders,
        ), true);
    }

    /** @return array<int, string> */
    public function acceptedSigningSecrets(int $now): array
    {
        $secrets = [$this->signingSecret];

        if ($this->pendingSigningSecret !== null) {
            $secrets[] = $this->pendingSigningSecret;
        }

        if ($this->previousSigningSecret !== null
            && $this->previousSecretExpiresAt !== null
            && $this->previousSecretExpiresAt >= $now) {
            $secrets[] = $this->previousSigningSecret;
        }

        return array_values(array_unique($secrets));
    }

    public function forDeliveryRoute(string $routeToken): self
    {
        if (hash_equals($this->routeToken, $routeToken)) {
            return $this;
        }

        return new self(
            $this->id,
            $routeToken,
            $this->webhookUrl,
            $this->signingSecret,
            $this->senders,
            $this->active,
            $this->label,
            $this->pendingSigningSecret,
            $this->previousSigningSecret,
            $this->previousSecretExpiresAt,
            $this->pendingRotationId,
            $this->lastRotationId,
            null,
            null,
            $this->lastRouteRotationId,
            $this->routeRotationAvailableAt,
            $this->publicAlias,
            $this->billingStatus,
            $this->stripeCustomerId,
            $this->stripeSubscriptionId,
            $this->billingPeriodEnd,
            $this->checkoutId,
            $this->checkoutUrl,
            $this->checkoutExpiresAt,
        );
    }

    public function hasRelayAccess(bool $subscriptionRequired, ?int $now = null): bool
    {
        if (! $this->active) {
            return false;
        }

        if (! $subscriptionRequired) {
            return true;
        }

        if (in_array($this->billingStatus, ['complimentary', 'active', 'trialing'], true)) {
            return true;
        }

        $now ??= time();

        return $this->billingStatus === 'past_due'
            && $this->billingPeriodEnd !== null
            && $this->billingPeriodEnd >= $now;
    }

    private static function validRotationId(string $rotationId): bool
    {
        return preg_match('/^sr_[A-Za-z0-9_-]{43}$/D', $rotationId) === 1;
    }

    private static function validRouteRotationId(string $rotationId): bool
    {
        return preg_match('/^rr_[A-Za-z0-9_-]{43}$/D', $rotationId) === 1;
    }

    private static function validWebhook(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && mb_strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host'])
            && (($parts['port'] ?? 443) === 443)
            && (($parts['path'] ?? '') === '/_secretary/webhooks/relay/inbound')
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    private static function validCheckoutUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && mb_strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && mb_strtolower((string) ($parts['host'] ?? '')) === 'checkout.stripe.com'
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    /** @param  array<int, string>  $senders */
    private static function validSenders(array $senders): bool
    {
        $normalized = [];

        foreach ($senders as $sender) {
            if (! is_string($sender)
                || filter_var(mb_strtolower(trim($sender)), FILTER_VALIDATE_EMAIL) === false) {
                return false;
            }

            $normalized[] = mb_strtolower(trim($sender));
        }

        return count($normalized) === count(array_unique($normalized));
    }
}
