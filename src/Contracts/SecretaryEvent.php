<?php

namespace AxelFerdinand\StatamicSecretary\Contracts;

interface SecretaryEvent
{
    public function name(): string;

    /** @return array<string, mixed> */
    public function payload(): array;
}
