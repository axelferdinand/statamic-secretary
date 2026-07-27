<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

use AxelFerdinand\StatamicSecretaryRelay\Exceptions\RelayRejected;

final readonly class PairingDefinition
{
    /** @param  array<int, string>  $senders */
    public function __construct(
        public string $label,
        public array $senders,
    ) {
        if ($label === ''
            || mb_strlen($label) > 120
            || count($senders) < 1
            || count($senders) > 500) {
            throw new RelayRejected('Pairing definition is invalid.');
        }

        $normalized = [];

        foreach ($senders as $sender) {
            if (! is_string($sender)
                || filter_var(mb_strtolower(trim($sender)), FILTER_VALIDATE_EMAIL) === false) {
                throw new RelayRejected('Pairing definition is invalid.');
            }

            $normalized[] = mb_strtolower(trim($sender));
        }

        if (count($normalized) !== count(array_unique($normalized))) {
            throw new RelayRejected('Pairing definition is invalid.');
        }
    }

    /** @return array<int, string> */
    public function normalizedSenders(): array
    {
        return array_map(
            static fn (string $sender): string => mb_strtolower(trim($sender)),
            $this->senders,
        );
    }
}
