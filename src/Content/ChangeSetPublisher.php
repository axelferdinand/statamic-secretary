<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use AxelFerdinand\StatamicSecretary\Models\ChangeSet;
use Statamic\Contracts\Auth\User;

final class ChangeSetPublisher
{
    public function __construct(
        private readonly EntryChangeService $entries,
        private readonly StagedContentChangeService $staged,
    ) {}

    public function publish(ChangeSet $changeSet, User $user, ?string $message = null): ChangeSet
    {
        return match ($changeSet->resource_type) {
            'entry' => $this->entries->publish($changeSet, $user, $message),
            'term', 'global', 'navigation' => $this->staged->publish($changeSet, $user),
            default => throw new ContentOperationDenied("Unsupported change resource [{$changeSet->resource_type}]."),
        };
    }
}
