<?php

namespace AxelFerdinand\StatamicSecretary\Listeners;

use AxelFerdinand\StatamicSecretary\Contracts\SecretaryEvent;
use AxelFerdinand\StatamicSecretary\Jobs\DeliverSecretaryWebhook;

final class QueueSecretaryWebhook
{
    public function handle(SecretaryEvent $event): void
    {
        if (! config('secretary.developer.webhooks.enabled')) {
            return;
        }

        if (! in_array($event->name(), (array) config('secretary.developer.webhooks.events', []), true)) {
            return;
        }

        DeliverSecretaryWebhook::dispatch($event->name(), $event->payload());
    }
}
