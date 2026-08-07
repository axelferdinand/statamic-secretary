<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;

final readonly class BillingUpdate
{
    public const STATUSES = [
        'pending',
        'active',
        'trialing',
        'past_due',
        'canceled',
        'unpaid',
        'incomplete',
        'incomplete_expired',
        'paused',
    ];

    public function __construct(
        public string $eventId,
        public ?string $installationId,
        public ?string $subscriptionId,
        public ?string $customerId,
        public string $status,
        public ?int $periodEnd = null,
    ) {
        if (preg_match('/^evt_[A-Za-z0-9]+$/D', $eventId) !== 1
            || ($installationId !== null
                && preg_match('/^si_[a-z0-9_-]{20,125}$/D', $installationId) !== 1)
            || ($subscriptionId !== null
                && preg_match('/^sub_[A-Za-z0-9]+$/D', $subscriptionId) !== 1)
            || ($customerId !== null
                && preg_match('/^cus_[A-Za-z0-9]+$/D', $customerId) !== 1)
            || ! in_array($status, self::STATUSES, true)
            || ($periodEnd !== null && $periodEnd < 1)
            || ($installationId === null && $subscriptionId === null)) {
            throw new RelayRejected('Subscription update is invalid.');
        }
    }
}
