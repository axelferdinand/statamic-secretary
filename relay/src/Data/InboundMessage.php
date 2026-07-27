<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class InboundMessage
{
    public function __construct(
        public string $providerMessageId,
        public string $recipient,
        public string $sender,
        public string $body,
        public ?string $subject,
        public bool $senderAuthenticated,
        public ?float $spamScore = null,
        public ?string $rfcMessageId = null,
    ) {}

    /** @return array<string, mixed> */
    public function sitePayload(string $routeToken, ?string $conversationToken): array
    {
        return [
            'version' => 1,
            'provider_message_id' => $this->providerMessageId,
            'sender' => mb_strtolower(trim($this->sender)),
            'subject' => $this->subject,
            'body' => $this->body,
            'sender_authenticated' => $this->senderAuthenticated,
            'spam_score' => $this->spamScore,
            'route_token' => $routeToken,
            'conversation_token' => $conversationToken,
            'rfc_message_id' => $this->rfcMessageId,
        ];
    }
}
