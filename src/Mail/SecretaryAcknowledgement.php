<?php

namespace AxelFerdinand\StatamicSecretary\Mail;

use AxelFerdinand\StatamicSecretary\Email\EmailConfiguration;
use AxelFerdinand\StatamicSecretary\Email\ReplyLanguage;
use AxelFerdinand\StatamicSecretary\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class SecretaryAcknowledgement extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Message $inbound) {}

    public function envelope(): Envelope
    {
        $email = app(EmailConfiguration::class);
        $subject = Str::limit(preg_replace('/\s+/u', ' ', trim((string) data_get($this->inbound->metadata, 'subject'))) ?: '', 180, '');

        return new Envelope(
            from: new Address($email->fromAddress(), $email->fromName()),
            replyTo: [new Address($this->replyAddress(), $email->fromName())],
            subject: str_starts_with(mb_strtolower($subject), 're:') ? $subject : 'Re: '.($subject ?: 'Statamic Secretary'),
        );
    }

    public function content(): Content
    {
        $language = app(ReplyLanguage::class);
        $locale = $language->forMessage($this->inbound);
        $copy = $language->copy($locale);

        return new Content(
            view: 'statamic-secretary::emails.acknowledgement',
            text: 'statamic-secretary::emails.acknowledgement-text',
            with: [
                'locale' => $locale,
                'title' => $copy['acknowledgement_title'],
                'body' => $copy['acknowledgement_body'],
                'replyInstruction' => $copy['reply_to_continue'],
            ],
        );
    }

    public function headers(): Headers
    {
        $messageId = (string) data_get($this->inbound->metadata, 'rfc_message_id');

        if ($messageId === '') {
            return new Headers;
        }

        return new Headers(
            references: [$messageId],
            text: ['In-Reply-To' => $messageId],
        );
    }

    private function replyAddress(): string
    {
        $address = app(EmailConfiguration::class)->inboundAddress();
        [$local, $domain] = array_pad(explode('@', $address, 2), 2, '');

        return $domain === '' ? $address : $local.'+'.$this->inbound->conversation_id.'@'.$domain;
    }
}
