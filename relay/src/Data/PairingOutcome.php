<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Data;

final readonly class PairingOutcome
{
    public function __construct(
        public Installation $installation,
        public bool $duplicate,
    ) {}
}
