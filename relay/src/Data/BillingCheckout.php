<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;

final readonly class BillingCheckout
{
    public function __construct(
        public string $id,
        public string $url,
        public int $expiresAt,
    ) {
        $parts = parse_url($url);

        if (preg_match('/^cs_(?:test|live)_[A-Za-z0-9]+$/D', $id) !== 1
            || ! is_array($parts)
            || mb_strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || mb_strtolower((string) ($parts['host'] ?? '')) !== 'checkout.stripe.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || $expiresAt <= time()) {
            throw new RelayRejected('Subscription checkout is invalid.');
        }
    }
}
