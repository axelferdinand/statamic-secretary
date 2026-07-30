<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

use AxelFerdinand\StatamicSecretaryRelay\Data\ClaimState;

interface PostmarkPollStore
{
    public function claimPostmarkPoll(string $providerMessageId): ClaimState;

    public function completePostmarkPoll(string $providerMessageId): void;

    public function releasePostmarkPoll(string $providerMessageId): void;
}
