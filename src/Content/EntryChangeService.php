<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentConflict;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use AxelFerdinand\StatamicSecretary\Models\ChangeSet;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Models\Message;
use Illuminate\Support\Arr;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Support\Str;

final class EntryChangeService
{
    public function __construct(
        private readonly AllowedCollections $allowedCollections,
        private readonly ContentPathGuard $pathGuard,
        private readonly EntrySnapshotter $snapshotter,
        private readonly BlueprintValues $blueprintValues,
    ) {}

    /** @param  array<string, mixed>  $patch */
    public function proposeUpdate(
        Conversation $conversation,
        string $entryId,
        array $patch,
        ?string $summary = null,
        ?Message $message = null,
    ): ChangeSet {
        $entry = $this->findEntry($entryId);
        $before = $this->snapshotter->snapshot($entry);
        $after = $this->validatedAfterSnapshot($entry, $before, $patch);

        return $conversation->changeSets()->create([
            'proposed_by_message_id' => $message?->id,
            'status' => 'proposed',
            'operation' => 'update',
            'resource_type' => 'entry',
            'resource_id' => $entry->id(),
            'collection' => $entry->collection()->handle(),
            'site' => $entry->locale(),
            'blueprint' => $entry->blueprint()->handle(),
            'slug' => $entry->slug(),
            'parent_id' => $entry->parent()?->id(),
            'base_fingerprint' => $this->snapshotter->fingerprint($before),
            'live_base_fingerprint' => $this->snapshotter->liveFingerprint($entry),
            'before' => $before,
            'patch' => $patch,
            'after' => $after,
            'summary' => $summary,
        ]);
    }

    public function applyDraft(ChangeSet $changeSet, User $user): ChangeSet
    {
        $this->ensureUpdateState($changeSet, 'proposed');

        $entry = $this->findEntry((string) $changeSet->resource_id);
        $this->authorize($user, 'edit', $entry);

        $current = $this->snapshotter->snapshot($entry);

        if (! hash_equals((string) $changeSet->base_fingerprint, $this->snapshotter->fingerprint($current))) {
            throw new ContentConflict('The entry changed after Secretary prepared this proposal.');
        }

        if ($changeSet->live_base_fingerprint && ! hash_equals((string) $changeSet->live_base_fingerprint, $this->snapshotter->liveFingerprint($entry))) {
            throw new ContentConflict('The published entry changed after Secretary prepared this proposal.');
        }

        if (! $changeSet->live_base_fingerprint) {
            $changeSet->update(['live_base_fingerprint' => $this->snapshotter->liveFingerprint($entry)]);
        }

        if ($entry->published() && config('secretary.content.require_revisions_for_published_entries', true) && ! $entry->revisionsEnabled()) {
            throw new ContentOperationDenied('Revisions must be enabled before Secretary can draft changes to a published entry.');
        }

        $authoringEntry = $entry->published() && $entry->revisionsEnabled() && ! $entry->hasWorkingCopy()
            ? clone $entry
            : $this->snapshotter->authoringEntry($entry);
        $after = $this->validatedAfterSnapshot($authoringEntry, $current, (array) $changeSet->patch);
        $authoringEntry->data(Arr::get($after, 'data', []));

        if ($entry->published() && $entry->revisionsEnabled()) {
            $saved = $entry->hasWorkingCopy()
                ? $authoringEntry->saveToWorkingCopy()
                : $authoringEntry->makeWorkingCopy()->user($user)->save();
        } else {
            $saved = $authoringEntry->updateLastModified($user)->save();
        }

        if (! $saved) {
            throw new ContentOperationDenied('Statamic refused to save the Secretary draft.');
        }

        $freshEntry = $this->findEntry((string) $changeSet->resource_id);
        $draft = $this->snapshotter->snapshot($freshEntry);

        $changeSet->update([
            'status' => 'draft',
            'after' => $draft,
            'draft_fingerprint' => $this->snapshotter->fingerprint($draft),
            'applied_at' => now(),
            'failure' => null,
        ]);

        return $changeSet->fresh();
    }

    /** @param  array<string, mixed>  $data */
    public function proposeCreate(
        Conversation $conversation,
        string $collectionHandle,
        string $blueprintHandle,
        string $siteHandle,
        string $slug,
        array $data,
        ?string $parentId = null,
        ?string $summary = null,
        ?Message $message = null,
    ): ChangeSet {
        [$collection, $blueprint, $site, $slug, $values] = $this->validateCreateInput(
            $collectionHandle,
            $blueprintHandle,
            $siteHandle,
            $slug,
            $data,
            $parentId,
        );
        $resourceId = (string) Str::uuid();
        $after = [
            'id' => $resourceId,
            'collection' => $collection->handle(),
            'site' => $site->handle(),
            'blueprint' => $blueprint->handle(),
            'slug' => $slug,
            'parent_id' => $parentId,
            'published' => false,
            'has_working_copy' => false,
            'data' => $values,
        ];

        return $conversation->changeSets()->create([
            'proposed_by_message_id' => $message?->id,
            'status' => 'proposed',
            'operation' => 'create',
            'resource_type' => 'entry',
            'resource_id' => $resourceId,
            'collection' => $collection->handle(),
            'site' => $site->handle(),
            'blueprint' => $blueprint->handle(),
            'slug' => $slug,
            'parent_id' => $parentId,
            'patch' => $data,
            'after' => $after,
            'draft_fingerprint' => $this->snapshotter->fingerprint($after),
            'summary' => $summary,
        ]);
    }

    public function applyCreateDraft(ChangeSet $changeSet, User $user): ChangeSet
    {
        $this->ensureState($changeSet, 'create', 'proposed');

        if ($changeSet->resource_id && Entry::find((string) $changeSet->resource_id)) {
            return $this->resumeCreatedDraft($changeSet, $user);
        }

        [$collection, $blueprint, $site, $slug, $values, $parent] = $this->validateCreateInput(
            (string) $changeSet->collection,
            (string) $changeSet->blueprint,
            (string) $changeSet->site,
            (string) $changeSet->slug,
            (array) $changeSet->patch,
            $changeSet->parent_id,
        );

        if (! $user->can('create', [EntryContract::class, $collection, $site])) {
            throw new ContentOperationDenied("The requesting user is not allowed to create entries in [{$collection->handle()}].");
        }

        if ($parent && ! $user->can('view', $parent)) {
            throw new ContentOperationDenied('The requesting user is not allowed to use the selected parent entry.');
        }

        $entry = Entry::make()
            ->id((string) $changeSet->resource_id)
            ->collection($collection)
            ->blueprint($blueprint->handle())
            ->locale($site->handle())
            ->slug($slug)
            ->published(false)
            ->data($values);

        $this->pathGuard->ensure($entry->path());

        if ($structure = $collection->structure()) {
            $tree = $structure->in($site->handle());
            $this->pathGuard->ensure($tree->path());
            $entry->afterSave(function ($savedEntry) use ($tree, $parent): void {
                $parent ? $tree->appendTo($parent->id(), $savedEntry) : $tree->append($savedEntry);
                $tree->save();
            });
        }

        $saved = $entry->revisionsEnabled()
            ? $entry->store(['message' => $changeSet->summary, 'user' => $user])
            : $entry->updateLastModified($user)->save();

        if (! $saved) {
            throw new ContentOperationDenied('Statamic refused to save the new Secretary draft.');
        }

        $freshEntry = $this->findEntry($entry->id());
        $draft = $this->snapshotter->snapshot($freshEntry);

        return $this->markDraft($changeSet, $draft);
    }

    public function publish(ChangeSet $changeSet, User $user, ?string $message = null): ChangeSet
    {
        if (! in_array($changeSet->operation, ['create', 'update'], true) || $changeSet->resource_type !== 'entry' || $changeSet->status !== 'draft') {
            throw new ContentOperationDenied("This change set cannot be published from its current [{$changeSet->status}] state.");
        }

        $entry = $this->findEntry((string) $changeSet->resource_id);
        $this->authorize($user, 'publish', $entry);

        $current = $this->snapshotter->snapshot($entry);

        if (hash_equals(
            $this->recoveryFingerprint($this->expectedPublishedSnapshot($changeSet)),
            $this->recoveryFingerprint($current),
        )) {
            return $this->markPublished($changeSet);
        }

        if (! hash_equals((string) $changeSet->draft_fingerprint, $this->snapshotter->fingerprint($current))) {
            throw new ContentConflict('The draft changed after Secretary prepared it and will not be published automatically.');
        }

        if ($changeSet->live_base_fingerprint && ! hash_equals((string) $changeSet->live_base_fingerprint, $this->snapshotter->liveFingerprint($entry))) {
            throw new ContentConflict('The published entry changed while the Secretary draft was open and will not be overwritten.');
        }

        $published = $entry->publish([
            'user' => $user,
            'message' => $message ?: $changeSet->summary,
        ]);

        if (! $published) {
            throw new ContentOperationDenied('Statamic refused to publish the Secretary draft.');
        }

        return $this->markPublished($changeSet);
    }

    /** @return array<string, mixed> */
    private function expectedPublishedSnapshot(ChangeSet $changeSet): array
    {
        return [
            ...(array) $changeSet->after,
            'published' => true,
            'has_working_copy' => false,
        ];
    }

    /** @param  array<string, mixed>  $snapshot */
    private function recoveryFingerprint(array $snapshot): string
    {
        unset($snapshot['data']['updated_by'], $snapshot['data']['updated_at']);

        return $this->snapshotter->fingerprint($snapshot);
    }

    private function resumeCreatedDraft(ChangeSet $changeSet, User $user): ChangeSet
    {
        $entry = $this->findEntry((string) $changeSet->resource_id);
        $collection = $entry->collection();
        $site = Site::get($entry->locale());

        if (! $site || ! $user->can('create', [EntryContract::class, $collection, $site])) {
            throw new ContentOperationDenied("The requesting user is not allowed to create entries in [{$collection->handle()}].");
        }

        $current = $this->snapshotter->snapshot($entry);

        if (! hash_equals(
            $this->recoveryFingerprint(Arr::except((array) $changeSet->after, ['parent_id'])),
            $this->recoveryFingerprint(Arr::except($current, ['parent_id'])),
        )) {
            throw new ContentConflict('A different entry exists at the reserved Secretary draft ID. Nothing was overwritten.');
        }

        $this->restoreMissingStructureNode($entry, $changeSet, $user);
        $current = $this->snapshotter->snapshot($this->findEntry((string) $changeSet->resource_id));

        if (! hash_equals(
            $this->recoveryFingerprint((array) $changeSet->after),
            $this->recoveryFingerprint($current),
        )) {
            throw new ContentConflict('A different entry exists at the reserved Secretary draft ID. Nothing was overwritten.');
        }

        return $this->markDraft($changeSet, $current);
    }

    private function restoreMissingStructureNode(EntryContract $entry, ChangeSet $changeSet, User $user): void
    {
        $structure = $entry->collection()->structure();

        if (! $structure) {
            return;
        }

        $tree = $structure->in($entry->locale());

        if (! $tree) {
            throw new ContentOperationDenied('The structured collection has no tree for the Secretary draft site.');
        }

        $this->pathGuard->ensure($tree->path());

        if ($tree->find($entry->id())) {
            return;
        }

        if ($parentId = $changeSet->parent_id) {
            $parent = $this->findEntry((string) $parentId);
            $parentPage = $tree->find($parent->id());

            if (! $parentPage || $parent->collection()->handle() !== $entry->collection()->handle() || $parent->locale() !== $entry->locale()) {
                throw new ContentConflict('The parent for the recovered Secretary draft is no longer valid.');
            }

            if (! $user->can('view', $parent)) {
                throw new ContentOperationDenied('The requesting user is not allowed to use the selected parent entry.');
            }

            if ($structure->expectsRoot() && $parentPage->isRoot()) {
                throw new ContentOperationDenied('This collection does not allow pages beneath its root entry.');
            }

            if ($structure->maxDepth() && $parentPage->depth() >= $structure->maxDepth()) {
                throw new ContentOperationDenied("The recovered entry would exceed the collection's maximum structure depth.");
            }

            $tree->appendTo($parent->id(), $entry);
        } else {
            $tree->append($entry);
        }

        if ($tree->save() === false) {
            throw new ContentOperationDenied('Statamic refused to restore the new entry in its collection structure.');
        }
    }

    /** @param  array<string, mixed>  $draft */
    private function markDraft(ChangeSet $changeSet, array $draft): ChangeSet
    {
        $changeSet->update([
            'status' => 'draft',
            'after' => $draft,
            'draft_fingerprint' => $this->snapshotter->fingerprint($draft),
            'applied_at' => now(),
            'failure' => null,
        ]);

        return $changeSet->fresh();
    }

    private function markPublished(ChangeSet $changeSet): ChangeSet
    {
        $changeSet->update([
            'status' => 'published',
            'published_at' => now(),
            'failure' => null,
        ]);

        return $changeSet->fresh();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function validatedAfterSnapshot(EntryContract $entry, array $before, array $patch): array
    {
        $after = $before;
        $after['data'] = $this->blueprintValues->mergeAndValidate(
            $entry->blueprint(),
            (array) Arr::get($before, 'data', []),
            $patch,
            storageExisting: (array) Arr::get($before, 'data', []),
        );

        return $after;
    }

    private function findEntry(string $id): EntryContract
    {
        $entry = Entry::find($id)
            ?? throw new ContentOperationDenied("Entry [{$id}] was not found.");

        $this->allowedCollections->ensure($entry->collection()->handle());
        $this->pathGuard->ensure($entry->path());

        return $entry;
    }

    private function ensureUpdateState(ChangeSet $changeSet, string $status): void
    {
        $this->ensureState($changeSet, 'update', $status);
    }

    private function ensureState(ChangeSet $changeSet, string $operation, string $status): void
    {
        if ($changeSet->operation !== $operation || $changeSet->resource_type !== 'entry' || $changeSet->status !== $status) {
            throw new ContentOperationDenied("This change set cannot be used from its current [{$changeSet->status}] state.");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: mixed, 1: mixed, 2: mixed, 3: string, 4: array<string, mixed>, 5?: EntryContract|null}
     */
    private function validateCreateInput(
        string $collectionHandle,
        string $blueprintHandle,
        string $siteHandle,
        string $slug,
        array $data,
        ?string $parentId,
    ): array {
        $this->allowedCollections->ensure($collectionHandle);
        $collection = Collection::find($collectionHandle)
            ?? throw new ContentOperationDenied("Collection [{$collectionHandle}] was not found.");
        $site = Site::get($siteHandle);

        if (! $site || ! $collection->sites()->contains($siteHandle)) {
            throw new ContentOperationDenied("Site [{$siteHandle}] is not available for [{$collectionHandle}].");
        }

        $blueprint = $collection->entryBlueprint($blueprintHandle)
            ?? throw new ContentOperationDenied("Blueprint [{$blueprintHandle}] was not found in [{$collectionHandle}].");
        $slug = trim($slug);

        if ($slug === '' || $slug !== Str::slug($slug)) {
            throw new ContentOperationDenied('The entry slug must be a non-empty, normalized slug without path separators.');
        }

        if (Entry::query()->where('collection', $collectionHandle)->where('site', $siteHandle)->where('slug', $slug)->first()) {
            throw new ContentOperationDenied("An entry with slug [{$slug}] already exists in [{$collectionHandle}].");
        }

        $values = $this->blueprintValues->mergeAndValidate($blueprint, [], $data);
        $parent = null;

        if ($parentId !== null) {
            if (! $collection->hasStructure()) {
                throw new ContentOperationDenied('A parent may only be supplied for a structured collection.');
            }

            $parent = $this->findEntry($parentId);

            if ($parent->collection()->handle() !== $collectionHandle || $parent->locale() !== $siteHandle) {
                throw new ContentOperationDenied('The parent must belong to the same collection and site.');
            }

            $structure = $collection->structure();
            $tree = $structure?->in($siteHandle);
            $parentPage = $tree?->find($parent->id());

            if (! $tree || ! $parentPage) {
                throw new ContentOperationDenied('The parent must exist in the collection structure.');
            }

            $this->pathGuard->ensure($tree->path());

            if ($structure->expectsRoot() && $parentPage->isRoot()) {
                throw new ContentOperationDenied('This collection does not allow pages beneath its root entry.');
            }

            if ($structure->maxDepth() && $parentPage->depth() >= $structure->maxDepth()) {
                throw new ContentOperationDenied("The new entry would exceed the collection's maximum structure depth.");
            }
        }

        return [$collection, $blueprint, $site, $slug, $values, $parent];
    }

    private function authorize(User $user, string $ability, EntryContract $entry): void
    {
        if (! $user->can($ability, $entry)) {
            throw new ContentOperationDenied("The requesting user is not allowed to {$ability} this entry.");
        }
    }
}
