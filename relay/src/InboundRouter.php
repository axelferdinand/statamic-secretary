<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\RelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SiteTransport;
use AxelFerdinand\StatamicSecretaryRelay\Data\ClaimState;
use AxelFerdinand\StatamicSecretaryRelay\Data\ConversationRoute;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundDelivery;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundMessage;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Data\RouteOutcome;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use JsonException;
use Throwable;

final class InboundRouter
{
    public function __construct(
        private readonly RelayStore $store,
        private readonly SiteTransport $transport,
        private readonly RelayAddress $address,
        private readonly bool $requireSenderAuthentication = true,
        private readonly float $maximumSpamScore = 5.0,
    ) {}

    public function route(InboundMessage $message): RouteOutcome
    {
        $this->validate($message);
        $sender = mb_strtolower(trim($message->sender));
        $parsed = $this->address->parse($message->recipient);

        if ($parsed->routeToken === null) {
            $candidates = array_values(array_filter(
                $this->store->installationsForSender($sender),
                fn (Installation $installation): bool => $installation->active && $installation->allowsSender($sender),
            ));

            if ($candidates === []) {
                throw new RelayRejected('Sender is not registered for an active installation.');
            }

            if (count($candidates) !== 1) {
                return new RouteOutcome(
                    'selection_required',
                    candidateRouteTokens: array_map(fn (Installation $installation): string => $installation->routeToken, $candidates),
                );
            }

            $installation = $candidates[0];
            $selectedRouteToken = $installation->routeToken;
        } else {
            $installation = $this->store->installationByRouteToken($parsed->routeToken);
            $selectedRouteToken = $parsed->routeToken;

            if (! $installation || ! $installation->active || ! $installation->allowsSender($sender)) {
                throw new RelayRejected('Route is not available to this sender.');
            }

            if (! hash_equals($installation->routeToken, $selectedRouteToken)
                && $parsed->conversationToken === null) {
                throw new RelayRejected('Retired or pending routes cannot start a new conversation.');
            }
        }

        $existingConversation = null;

        if ($parsed->conversationToken !== null) {
            $existingConversation = $this->store->conversationByToken($parsed->conversationToken);

            if (! $existingConversation
                || ! hash_equals($existingConversation->installationId, $installation->id)
                || ! hash_equals($existingConversation->routeToken, $selectedRouteToken)
                || ! hash_equals(mb_strtolower($existingConversation->sender), $sender)) {
                throw new RelayRejected('Conversation does not belong to the selected route and sender.');
            }
        }

        $claim = $this->store->claimInbound(
            $message->providerMessageId,
            $installation->id,
            $this->fingerprint($message),
        );

        if ($claim === ClaimState::Conflict) {
            throw new RelayRejected('Provider message identity conflicts with an existing claim.');
        }

        if ($claim === ClaimState::Complete) {
            $delivery = $this->store->inboundDelivery($message->providerMessageId);

            if (! $delivery
                || ! hash_equals($delivery->installationId, $installation->id)
                || ! hash_equals($delivery->sender, $sender)
                || ! hash_equals($delivery->routeToken, $selectedRouteToken)
                || ($parsed->conversationToken !== null && ! hash_equals($delivery->conversationToken, $parsed->conversationToken))) {
                throw new RelayRejected('Duplicate provider message does not match its original route.');
            }

            return new RouteOutcome('duplicate', $installation->id, $delivery->conversationToken);
        }

        if ($claim === ClaimState::Processing) {
            return new RouteOutcome('processing', $installation->id, $parsed->conversationToken);
        }

        try {
            $deliveryMessage = $this->requireSenderAuthentication
                ? $message
                : $message->authorizedForRegisteredSender();
            $result = $this->transport->deliver(
                $installation->forDeliveryRoute($selectedRouteToken),
                $deliveryMessage,
                $parsed->conversationToken,
            );
            $conversation = new ConversationRoute(
                $result->conversationToken,
                $installation->id,
                $selectedRouteToken,
                $sender,
            );
            $this->store->saveConversation($conversation);
            $this->store->completeInbound(new InboundDelivery(
                $message->providerMessageId,
                $installation->id,
                $sender,
                $selectedRouteToken,
                $result->conversationToken,
            ));
        } catch (Throwable $exception) {
            $this->store->releaseInbound($message->providerMessageId, $installation->id);

            throw $exception;
        }

        return new RouteOutcome('forwarded', $installation->id, $result->conversationToken);
    }

    private function validate(InboundMessage $message): void
    {
        if ($message->providerMessageId === ''
            || mb_strlen($message->providerMessageId) > 255
            || filter_var(mb_strtolower(trim($message->sender)), FILTER_VALIDATE_EMAIL) === false
            || trim($message->body) === ''
            || mb_strlen($message->body) > 20000
            || ($this->requireSenderAuthentication && ! $message->senderAuthenticated)
            || ($message->spamScore !== null && $message->spamScore > $this->maximumSpamScore)) {
            throw new RelayRejected('Inbound message failed relay validation.');
        }
    }

    private function fingerprint(InboundMessage $message): string
    {
        try {
            $identity = json_encode([
                'provider_message_id' => $message->providerMessageId,
                'recipient' => mb_strtolower(trim($message->recipient)),
                'sender' => mb_strtolower(trim($message->sender)),
                'body' => $message->body,
                'subject' => $message->subject,
                'sender_authenticated' => $message->senderAuthenticated,
                'spam_score' => $message->spamScore,
                'rfc_message_id' => $message->rfcMessageId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RelayRejected('Inbound message identity could not be encoded.', previous: $exception);
        }

        return hash('sha256', $identity);
    }
}
