<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class SelectionNotice
{
    /** @param  array<int, array{label: string, address: string}>  $candidates */
    public function __construct(
        public string $providerMessageId,
        public string $recipient,
        public array $candidates,
        public ?string $inReplyTo = null,
    ) {}
}
