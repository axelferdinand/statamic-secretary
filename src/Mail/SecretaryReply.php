<?php

namespace AxelFerdinand\StatamicSecretary\Mail;

use AxelFerdinand\StatamicSecretary\Email\EmailConfiguration;
use AxelFerdinand\StatamicSecretary\Email\ReplyAttachmentPresenter;
use AxelFerdinand\StatamicSecretary\Email\ReplyChangeSetPresenter;
use AxelFerdinand\StatamicSecretary\Email\ReplyLanguage;
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
            subject: str_starts_with(mb_strtolower($subject), 're:') ? $subject : 'Re: '.($subject ?: 'Secretary'),
        );
    }

    public function content(): Content
    {
        $language = app(ReplyLanguage::class);
        $inbound = $this->conversation->messages()
            ->whereKey($this->reply->reply_to_message_id ?: data_get($this->reply->metadata, 'reply_to_message_id'))
            ->first();
        $locale = $inbound
            ? $language->forMessage($inbound)
            : $language->detect($this->reply->body);
        $copy = $language->copy($locale);
        $changeSets = app(ReplyChangeSetPresenter::class)->present(
            $this->conversation,
            $this->reply,
        );
        $nativeChanges = array_values(array_filter(
            $changeSets,
            fn (array $changeSet): bool => is_string($changeSet['native_url']),
        ));
        $primaryChange = count($nativeChanges) === 1 ? $nativeChanges[0] : null;
        $affectedChanges = array_values(array_filter(
            $changeSets,
            fn (array $changeSet): bool => is_string($changeSet['public_url']),
        ));
        $affectedChange = count($affectedChanges) === 1 ? $affectedChanges[0] : null;
        $conversationUrl = app(ReplyChangeSetPresenter::class)->conversationUrl($this->conversation);
        $bodySections = app(ReplyChangeSetPresenter::class)->emailBodySections(
            $this->reply->body,
            $changeSets,
        );
        $attachments = app(ReplyAttachmentPresenter::class)->present(
            $this->conversation,
            $this->reply,
        );
        $primaryUrl = $primaryChange
            ? $primaryChange['native_url']
            : $conversationUrl;

        return new Content(
            view: 'statamic-secretary::emails.reply',
            text: 'statamic-secretary::emails.reply-text',
            with: [
                'bodyBeforeAffected' => $bodySections['before'],
                'bodyAfterAffected' => $bodySections['after'],
                'locale' => $locale,
                'copy' => $copy,
                'primaryUrl' => $primaryUrl,
                'primaryLabel' => $primaryChange
                    ? ($primaryChange['status'] === 'published'
                        ? $copy['open_page']
                        : $copy['open_draft'])
                    : ($changeSets === []
                        ? $copy['open_conversation']
                        : $copy['review_changes']),
                'conversationUrl' => $conversationUrl,
                'changeSets' => $changeSets,
                'attachments' => $attachments,
                'affectedChange' => $affectedChange,
                'showChangeList' => count($changeSets) > 1
                    || (count($changeSets) === 1 && $affectedChange === null),
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
