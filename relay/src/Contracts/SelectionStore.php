<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

use AxelFerdinand\StatamicSecretaryRelay\Data\ClaimState;

interface SelectionStore
{
    public function claimSelection(string $providerMessageId, string $fingerprint): ClaimState;

    public function completeSelection(string $providerMessageId, string $providerReplyId): void;

    public function releaseSelection(string $providerMessageId): void;

    public function completedSelectionProviderId(string $providerMessageId): ?string;
}
