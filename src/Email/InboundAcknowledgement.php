<?php

namespace AxelFerdinand\StatamicSecretary\Email;

use AxelFerdinand\StatamicSecretary\Mail\SecretaryAcknowledgement;
use AxelFerdinand\StatamicSecretary\Models\Message;
use AxelFerdinand\StatamicSecretary\Relay\RelayClient;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class InboundAcknowledgement
{
    public function __construct(
        private readonly EmailConfiguration $email,
        private readonly RelayClient $relay,
    ) {}

    public function send(Message $inbound): void
    {
        if (filled(data_get($inbound->metadata, 'acknowledgement_sent_at'))) {
            return;
        }

        try {
            if (data_get($inbound->conversation?->context, 'email_delivery') === 'relay') {
                $this->relay->sendAcknowledgement($inbound);
            } else {
                Mail::mailer($this->email->mailer())
                    ->to($inbound->conversation?->email)
                    ->send(new SecretaryAcknowledgement($inbound));
            }

            $inbound->update(['metadata' => [
                ...(array) $inbound->metadata,
                'acknowledgement_sent_at' => now()->toIso8601String(),
            ]]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
