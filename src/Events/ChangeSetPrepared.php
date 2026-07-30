<?php

namespace AxelFerdinand\StatamicSecretary\Events;

use AxelFerdinand\StatamicSecretary\Contracts\SecretaryEvent;
use AxelFerdinand\StatamicSecretary\Models\ChangeSet;
use Illuminate\Foundation\Events\Dispatchable;

final class ChangeSetPrepared implements SecretaryEvent
{
    use Dispatchable;

    public function __construct(public readonly ChangeSet $changeSet) {}

    public function name(): string
    {
        return 'change.prepared';
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
            'status' => $this->changeSet->status,
            'fields' => array_keys((array) $this->changeSet->patch),
            'created_at' => $this->changeSet->created_at?->toIso8601String(),
        ];
    }
}
