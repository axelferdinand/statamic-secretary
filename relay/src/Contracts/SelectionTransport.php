<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

use AxelFerdinand\StatamicSecretaryRelay\Data\SelectionNotice;

interface SelectionTransport
{
    public function send(SelectionNotice $notice): string;
}
