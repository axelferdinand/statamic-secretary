<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

use AxelFerdinand\StatamicSecretaryRelay\Data\BillingNotice;

interface BillingNoticeTransport
{
    public function send(BillingNotice $notice): string;
}
