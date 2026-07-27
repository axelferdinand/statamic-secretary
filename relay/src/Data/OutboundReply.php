<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class OutboundReply
{
    /** @param  array<int, array<string, string>>  $changeSets */
    public function __construct(
        public string $idempotencyKey,
        public string $installationId,
        public string $recipient,
        public string $subject,
        public string $body,
        public string $replyTo,
        public ?string $inReplyTo,
        public ?string $reviewUrl,
        public array $changeSets,
    ) {}
}
