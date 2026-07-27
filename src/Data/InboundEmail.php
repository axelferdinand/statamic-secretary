<?php

namespace AxelFerdinand\StatamicSecretary\Data;

final readonly class InboundEmail
{
    public function __construct(
        public string $providerMessageId,
        public string $sender,
        public string $body,
        public ?string $subject = null,
        public bool $senderAuthenticated = false,
        public ?float $spamScore = null,
        public ?string $rfcMessageId = null,
        public string $delivery = 'postmark',
        public ?string $threadToken = null,
        public ?string $routeToken = null,
    ) {}
}
