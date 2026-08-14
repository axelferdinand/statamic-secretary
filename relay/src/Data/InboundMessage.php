<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class InboundMessage
{
    /** @param  array<int, InboundAttachment>  $attachments */
    public function __construct(
        public string $providerMessageId,
        public string $recipient,
        public string $sender,
        public string $body,
        public ?string $subject,
        public bool $senderAuthenticated,
        public ?float $spamScore = null,
        public ?string $rfcMessageId = null,
        public array $attachments = [],
    ) {}

    /** @return array<string, mixed> */
    public function sitePayload(
        string $routeToken,
        ?string $conversationToken,
        bool $acknowledgementSent = false,
    ): array {
        return [
            'version' => $acknowledgementSent ? 3 : ($this->attachments === [] ? 1 : 2),
            'provider_message_id' => $this->providerMessageId,
            'sender' => mb_strtolower(trim($this->sender)),
            'subject' => $this->subject,
            'body' => $this->body,
            'sender_authenticated' => $this->senderAuthenticated,
            'spam_score' => $this->spamScore,
            'route_token' => $routeToken,
            'conversation_token' => $conversationToken,
            'rfc_message_id' => $this->rfcMessageId,
            ...($acknowledgementSent ? ['acknowledgement_sent' => true] : []),
            ...($this->attachments === [] ? [] : [
                'attachments' => array_map(
                    fn (InboundAttachment $attachment): array => $attachment->sitePayload(),
                    $this->attachments,
                ),
            ]),
        ];
    }

    public function authorizedForRegisteredSender(): self
    {
        return new self(
            $this->providerMessageId,
            $this->recipient,
            $this->sender,
            $this->body,
            $this->subject,
            true,
            $this->spamScore,
            $this->rfcMessageId,
            $this->attachments,
        );
    }
}
