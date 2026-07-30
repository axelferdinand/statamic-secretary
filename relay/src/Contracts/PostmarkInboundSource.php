<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

interface PostmarkInboundSource
{
    /** @return array<int, string> */
    public function pendingMessageIds(int $limit): array;

    /** @return array<string, mixed> */
    public function message(string $providerMessageId): array;
}
