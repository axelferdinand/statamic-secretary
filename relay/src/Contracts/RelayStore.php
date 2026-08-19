<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

use AxelFerdinand\StatamicSecretaryRelay\Data\ClaimState;
use AxelFerdinand\StatamicSecretaryRelay\Data\ConversationRoute;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundDelivery;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;

interface RelayStore
{
    public function installationById(string $id): ?Installation;

    public function installationByRouteToken(string $routeToken): ?Installation;

    public function installationByPublicAlias(string $publicAlias): ?Installation;

    /** @return array<int, Installation> */
    public function installationsForSender(string $sender): array;

    public function conversationByToken(string $token): ?ConversationRoute;

    public function saveConversation(ConversationRoute $conversation): void;

    public function claimInbound(string $providerMessageId, string $installationId, string $fingerprint): ClaimState;

    public function completeInbound(InboundDelivery $delivery): void;

    public function releaseInbound(string $providerMessageId, string $installationId): void;

    public function inboundDelivery(string $providerMessageId): ?InboundDelivery;

    public function consumeNonce(string $installationId, string $nonce, int $expiresAt): bool;

    public function claimReply(string $idempotencyKey, string $installationId, string $fingerprint): ClaimState;

    public function completeReply(string $idempotencyKey, string $installationId, string $providerMessageId): void;

    public function releaseReply(string $idempotencyKey, string $installationId): void;

    public function completedReplyProviderId(string $idempotencyKey, string $installationId): ?string;
}
