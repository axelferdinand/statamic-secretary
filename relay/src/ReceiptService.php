<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Contracts\MailTransport;
use AxelFerdinand\StatamicSecretaryRelay\Contracts\RelayStore;
use AxelFerdinand\StatamicSecretaryRelay\Data\ClaimState;
use AxelFerdinand\StatamicSecretaryRelay\Data\InboundMessage;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Data\OutboundReply;
use AxelFerdinand\StatamicSecretaryRelay\Data\ReceiptOutcome;
use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;
use Throwable;

final class ReceiptService
{
    public function __construct(
        private readonly RelayStore $store,
        private readonly MailTransport $mail,
        private readonly RelayAddress $address,
    ) {}

    public function send(
        Installation $installation,
        InboundMessage $message,
        string $routeToken,
        string $conversationToken,
    ): ReceiptOutcome {
        $started = hrtime(true);
        $locale = (new ReplyLanguage)->detect(trim(($message->subject ?? '')."\n".$message->body));
        $copy = (new ReplyLanguage)->copy($locale);
        $idempotencyKey = 'secretary-reply-receipt-'.hash('sha256', $message->providerMessageId);
        $fingerprint = hash('sha256', implode("\n", [
            $installation->id,
            mb_strtolower(trim($message->sender)),
            $routeToken,
            $conversationToken,
            $message->providerMessageId,
        ]));
        $claim = $this->store->claimReply($idempotencyKey, $installation->id, $fingerprint);

        if ($claim === ClaimState::Conflict) {
            throw new RelayRejected('Receipt idempotency key conflicts with an existing claim.');
        }

        if ($claim === ClaimState::Complete) {
            return new ReceiptOutcome('duplicate', $this->elapsed($started));
        }

        if ($claim === ClaimState::Processing) {
            return new ReceiptOutcome('processing', $this->elapsed($started));
        }

        $reply = new OutboundReply(
            $idempotencyKey,
            $installation->id,
            mb_strtolower(trim($message->sender)),
            $this->replySubject($message->subject, $copy['receipt_subject']),
            $copy['receipt_title']."\n\n".$copy['receipt_body'],
            $this->address->replyTo($routeToken, $conversationToken),
            $message->rfcMessageId,
            null,
            [],
            $locale,
        );

        try {
            $providerMessageId = $this->mail->send($reply);

            if ($providerMessageId === '' || mb_strlen($providerMessageId) > 255) {
                throw new RelayRejected('Mail transport returned an invalid receipt provider message ID.');
            }

            $this->store->completeReply($idempotencyKey, $installation->id, $providerMessageId);
        } catch (Throwable $exception) {
            $this->store->releaseReply($idempotencyKey, $installation->id);

            throw $exception;
        }

        return new ReceiptOutcome('sent', $this->elapsed($started));
    }

    private function replySubject(?string $subject, string $fallback): string
    {
        $subject = trim((string) $subject);

        if ($subject === '') {
            return $fallback;
        }

        return preg_match('/^re\s*:/iu', $subject) === 1 ? $subject : 'Re: '.$subject;
    }

    private function elapsed(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }
}
