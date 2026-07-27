<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

use AxelFerdinand\StatamicSecretaryRelay\Data\OutboundReply;

interface MailTransport
{
    public function send(OutboundReply $reply): string;
}
