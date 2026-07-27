<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\RelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SelectionStore;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SelectionTransport;
use AxelFerdinand\StatamicSecretaryRelay\Data\ClaimState;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundMessage;
use AxelFerdinand\StatamicSecretaryRelay\Data\SelectionNotice;
use AxelFerdinand\StatamicSecretaryRelay\Data\SelectionOutcome;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use JsonException;
use Throwable;

final class SelectionService
{
    public function __construct(
        private readonly RelayStore $installations,
        private readonly SelectionStore $claims,
        private readonly SelectionTransport $mail,
        private readonly RelayAddress $address,
    ) {}

    /** @param  array<int, string>  $routeTokens */
    public function notify(InboundMessage $message, array $routeTokens): SelectionOutcome
    {
        $sender = mb_strtolower(trim($message->sender));
        $routeTokens = array_values(array_unique($routeTokens));
        sort($routeTokens, SORT_STRING);

        if ($message->providerMessageId === ''
            || filter_var($sender, FILTER_VALIDATE_EMAIL) === false
            || count($routeTokens) < 2
            || count($routeTokens) > 50) {
            throw new RelayRejected('Selection request is invalid.');
        }

        $candidates = [];
        $candidateIdentity = [];

        foreach ($routeTokens as $routeToken) {
            $installation = $this->installations->installationByRouteToken($routeToken);

            if (! $installation
                || ! $installation->active
                || ! $installation->allowsSender($sender)
                || $installation->label === null) {
                throw new RelayRejected('Selection candidate is not available to this sender.');
            }

            $candidates[] = [
                'label' => $installation->label,
                'address' => $this->address->routeAddress($routeToken),
            ];
            $candidateIdentity[] = [$installation->id, $routeToken];
        }

        try {
            $identity = json_encode([
                'provider_message_id' => $message->providerMessageId,
                'sender' => $sender,
                'candidates' => $candidateIdentity,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RelayRejected('Selection identity could not be encoded.', previous: $exception);
        }

        $claim = $this->claims->claimSelection($message->providerMessageId, hash('sha256', $identity));

        if ($claim === ClaimState::Conflict) {
            throw new RelayRejected('Selection identity conflicts with an existing claim.');
        }

        if ($claim === ClaimState::Complete) {
            return new SelectionOutcome(
                'duplicate',
                $this->claims->completedSelectionProviderId($message->providerMessageId),
            );
        }

        if ($claim === ClaimState::Processing) {
            return new SelectionOutcome('processing');
        }

        try {
            $providerReplyId = $this->mail->send(new SelectionNotice(
                $message->providerMessageId,
                $sender,
                $candidates,
                $message->rfcMessageId,
            ));

            if ($providerReplyId === '' || mb_strlen($providerReplyId) > 255) {
                throw new RelayRejected('Selection transport returned an invalid provider message ID.');
            }

            $this->claims->completeSelection($message->providerMessageId, $providerReplyId);
        } catch (Throwable $exception) {
            $this->claims->releaseSelection($message->providerMessageId);

            throw $exception;
        }

        return new SelectionOutcome('sent', $providerReplyId);
    }
}
