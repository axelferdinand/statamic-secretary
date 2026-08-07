<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\BillingNoticeTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\RelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\SelectionStore;
use AxelFerdinand\StatamicSecretaryRelay\Data\BillingNotice;
use AxelFerdinand\StatamicSecretaryRelay\Data\BillingNoticeOutcome;
use AxelFerdinand\StatamicSecretaryRelay\Data\ClaimState;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundMessage;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use JsonException;
use Throwable;

final readonly class BillingNoticeService
{
    public function __construct(
        private RelayStore $installations,
        private SelectionStore $claims,
        private SubscriptionService $subscriptions,
        private BillingNoticeTransport $mail,
    ) {}

    public function notify(InboundMessage $message, string $installationId): BillingNoticeOutcome
    {
        $sender = mb_strtolower(trim($message->sender));
        $installation = $this->installations->installationById($installationId);

        if ($message->providerMessageId === ''
            || filter_var($sender, FILTER_VALIDATE_EMAIL) === false
            || ! $installation
            || ! $installation->active
            || ! $installation->allowsSender($sender)
            || $installation->hasRelayAccess(true)) {
            throw new RelayRejected('Billing notice request is invalid.');
        }

        $checkout = $this->subscriptions->checkout($installation);

        try {
            $identity = json_encode([
                'kind' => 'billing_notice',
                'provider_message_id' => $message->providerMessageId,
                'installation_id' => $installation->id,
                'sender' => $sender,
                'checkout_id' => $checkout->id,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RelayRejected('Billing notice identity could not be encoded.', previous: $exception);
        }

        $claim = $this->claims->claimSelection(
            $message->providerMessageId,
            hash('sha256', $identity),
        );

        if ($claim === ClaimState::Conflict) {
            throw new RelayRejected('Billing notice identity conflicts with an existing claim.');
        }

        if ($claim === ClaimState::Complete) {
            return new BillingNoticeOutcome(
                'duplicate',
                $this->claims->completedSelectionProviderId($message->providerMessageId),
            );
        }

        if ($claim === ClaimState::Processing) {
            return new BillingNoticeOutcome('processing');
        }

        try {
            $providerReplyId = $this->mail->send(new BillingNotice(
                $message->providerMessageId,
                $installation->id,
                $sender,
                $installation->label ?? 'your Statamic site',
                $checkout->url,
                $message->rfcMessageId,
            ));

            if ($providerReplyId === '' || mb_strlen($providerReplyId) > 255) {
                throw new RelayRejected('Billing notice transport returned an invalid provider message ID.');
            }

            $this->claims->completeSelection($message->providerMessageId, $providerReplyId);
        } catch (Throwable $exception) {
            $this->claims->releaseSelection($message->providerMessageId);

            throw $exception;
        }

        return new BillingNoticeOutcome('sent', $providerReplyId);
    }
}
