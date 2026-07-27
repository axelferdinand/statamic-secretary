<?php

namespace AxelFerdinand\StatamicSecretary\Mail;

use AxelFerdinand\StatamicSecretary\Email\EmailConfiguration;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class SecretaryReply extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly Message $reply,
    ) {}

    public function envelope(): Envelope
    {
        $email = app(EmailConfiguration::class);
        $fromAddress = $email->fromAddress();
        $fromName = $email->fromName();
        $subject = trim((string) data_get($this->conversation->messages()->where('channel', 'email')->oldest()->first()?->metadata, 'subject'));
        $subject = Str::limit(preg_replace('/\s+/u', ' ', $subject) ?: '', 180, '');

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            replyTo: [new Address($this->replyAddress(), $fromName)],
            subject: str_starts_with(mb_strtolower($subject), 're:') ? $subject : 'Re: '.($subject ?: 'Statamic Secretary'),
        );
    }

    public function content(): Content
    {
        $changeSetIds = (array) data_get($this->reply->metadata, 'change_set_ids', []);
        $changeSets = $this->conversation->changeSets()
            ->whereIn('id', $changeSetIds)
            ->get()
            ->map(fn ($change): array => [
                'id' => $change->id,
                'status' => $change->status,
                'summary' => $change->summary ?: $change->resource_id,
            ])->values()->all();

        return new Content(
            view: 'statamic-secretary::emails.reply',
            text: 'statamic-secretary::emails.reply-text',
            with: [
                'body' => $this->reply->body,
                'reviewUrl' => cp_route('secretary.show', $this->conversation),
                'changeSets' => $changeSets,
            ],
        );
    }

    public function headers(): Headers
    {
        $inbound = $this->conversation->messages()
            ->whereKey($this->reply->reply_to_message_id ?: data_get($this->reply->metadata, 'reply_to_message_id'))
            ->first();
        $messageId = (string) data_get($inbound?->metadata, 'rfc_message_id');

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

        return $domain === '' ? $address : $local.'+'.$this->conversation->id.'@'.$domain;
    }
}
