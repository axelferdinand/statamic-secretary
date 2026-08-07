<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

use AxelFerdinand\StatamicSecretaryRelay\Data\BillingCheckout;

interface BillingStore
{
    public function saveBillingCheckout(string $installationId, BillingCheckout $checkout): void;

    public function applyBillingEvent(
        string $eventId,
        ?string $installationId,
        ?string $subscriptionId,
        ?string $customerId,
        string $status,
        ?int $periodEnd,
    ): bool;
}
