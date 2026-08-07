<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\InstallationAdminStore;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;

final readonly class InstallationManager
{
    public function __construct(private InstallationAdminStore $store) {}

    public function status(string $installationId): Installation
    {
        if (preg_match('/^si_[a-z0-9_-]{20,125}$/D', $installationId) !== 1) {
            throw new RelayRejected('Installation identity is invalid.');
        }

        $installation = $this->store->installationById($installationId);

        if (! $installation) {
            throw new RelayRejected('Installation was not found.');
        }

        return $installation;
    }

    public function setActive(string $installationId, bool $active): Installation
    {
        $installation = $this->status($installationId);

        if ($installation->active === $active) {
            return $installation;
        }

        return $this->save($installation, active: $active);
    }

    public function addSender(string $installationId, string $sender): Installation
    {
        $installation = $this->status($installationId);
        $sender = $this->sender($sender);

        if ($installation->allowsSender($sender)) {
            return $installation;
        }

        $senders = [...$installation->senders, $sender];
        sort($senders, SORT_STRING);

        return $this->save($installation, senders: $senders);
    }

    public function removeSender(string $installationId, string $sender): Installation
    {
        $installation = $this->status($installationId);
        $sender = $this->sender($sender);

        if (! $installation->allowsSender($sender)) {
            return $installation;
        }

        return $this->save(
            $installation,
            senders: array_values(array_filter(
                $installation->senders,
                static fn (string $allowed): bool => mb_strtolower(trim($allowed)) !== $sender,
            )),
        );
    }

    public function setBillingStatus(string $installationId, string $status): Installation
    {
        if (! in_array($status, ['beta', 'complimentary', 'pending'], true)) {
            throw new RelayRejected('Operator billing status is invalid.');
        }

        $installation = $this->status($installationId);

        if ($installation->billingStatus === $status) {
            return $installation;
        }

        return $this->save($installation, billingStatus: $status);
    }

    /** @param  array<int, string>|null  $senders */
    private function save(
        Installation $installation,
        ?array $senders = null,
        ?bool $active = null,
        ?string $billingStatus = null,
    ): Installation {
        $updated = new Installation(
            $installation->id,
            $installation->routeToken,
            $installation->webhookUrl,
            $installation->signingSecret,
            $senders ?? $installation->senders,
            $active ?? $installation->active,
            $installation->label,
            $installation->pendingSigningSecret,
            $installation->previousSigningSecret,
            $installation->previousSecretExpiresAt,
            $installation->pendingRotationId,
            $installation->lastRotationId,
            $installation->pendingRouteToken,
            $installation->pendingRouteRotationId,
            $installation->lastRouteRotationId,
            $installation->routeRotationAvailableAt,
            $installation->publicAlias,
            $billingStatus ?? $installation->billingStatus,
            $installation->stripeCustomerId,
            $installation->stripeSubscriptionId,
            $installation->billingPeriodEnd,
            $installation->checkoutId,
            $installation->checkoutUrl,
            $installation->checkoutExpiresAt,
        );
        $this->store->saveInstallation($updated);

        return $updated;
    }

    private function sender(string $sender): string
    {
        $sender = mb_strtolower(trim($sender));

        if (filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
            throw new RelayRejected('Sender address is invalid.');
        }

        return $sender;
    }
}
