<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

use AxelFerdinand\StatamicSecretaryRelay\Data\BillingCheckout;
use AxelFerdinand\StatamicSecretaryRelay\Data\BillingUpdate;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;

interface SubscriptionGateway
{
    public function createCheckout(Installation $installation): BillingCheckout;

    /** @param  array<string, string>  $headers */
    public function webhook(array $headers, string $body): ?BillingUpdate;
}
