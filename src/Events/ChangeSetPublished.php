<?php

namespace AxelFerdinand\StatamicSecretary\Events;

use AxelFerdinand\StatamicSecretary\Contracts\SecretaryEvent;
use AxelFerdinand\StatamicSecretary\Models\ChangeSet;
use Illuminate\Foundation\Events\Dispatchable;

final class ChangeSetPublished implements SecretaryEvent
{
    use Dispatchable;

    public function __construct(public readonly ChangeSet $changeSet) {}

    public function name(): string
    {
        return 'change.published';
    }

    public function payload(): array
    {
        return [
            'change_set_id' => $this->changeSet->id,
            'conversation_id' => $this->changeSet->conversation_id,
            'operation' => $this->changeSet->operation,
            'resource_type' => $this->changeSet->resource_type,
            'resource_id' => $this->changeSet->resource_id,
            'collection' => $this->changeSet->collection,
            'site' => $this->changeSet->site,
            'published_at' => $this->changeSet->published_at?->toIso8601String(),
        ];
    }
}
