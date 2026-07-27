<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

use AxelFerdinand\StatamicSecretaryRelay\Data\PairingCodeNotice;

interface PairingCodeTransport
{
    public function send(PairingCodeNotice $notice): string;
}
