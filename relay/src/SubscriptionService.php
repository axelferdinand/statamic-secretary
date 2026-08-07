<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\BillingStore;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SubscriptionGateway;
use AxelFerdinand\StatamicSecretaryRelay\Data\BillingCheckout;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;

final readonly class SubscriptionService
{
    public function __construct(
        private BillingStore $store,
        private SubscriptionGateway $gateway,
    ) {}

    public function checkout(Installation $installation): BillingCheckout
    {
        if ($installation->checkoutId !== null
            && $installation->checkoutUrl !== null
            && $installation->checkoutExpiresAt !== null
            && $installation->checkoutExpiresAt > time() + 60) {
            return new BillingCheckout(
                $installation->checkoutId,
                $installation->checkoutUrl,
                $installation->checkoutExpiresAt,
            );
        }

        $checkout = $this->gateway->createCheckout($installation);
        $this->store->saveBillingCheckout($installation->id, $checkout);

        return $checkout;
    }

    /** @param  array<string, string>  $headers */
    public function acceptWebhook(array $headers, string $body): bool
    {
        $update = $this->gateway->webhook($headers, $body);

        if ($update === null) {
            return false;
        }

        return $this->store->applyBillingEvent(
            $update->eventId,
            $update->installationId,
            $update->subscriptionId,
            $update->customerId,
            $update->status,
            $update->periodEnd,
        );
    }
}
