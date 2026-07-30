<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use AxelFerdinand\StatamicSecretary\Events\ChangeSetPrepared;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentConflict;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use AxelFerdinand\StatamicSecretary\Models\ChangeSet;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Models\Message;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Support\Arr;
use Statamic\Support\Str;

final class StagedContentChangeService
{
    public function __construct(
        private readonly ContentResourceCatalog $catalog,
        private readonly AllowedContentSources $allowedSources,
        private readonly AllowedCollections $allowedCollections,
        private readonly ContentPathGuard $pathGuard,
        private readonly BlueprintValues $blueprintValues,
    ) {}

    /** @param  array<string, mixed>  $patch */
    public function proposeUpdate(
        Conversation $conversation,
        string $type,
        string $resourceId,
        string $site,
        array $patch,
        User $user,
        ?string $summary = null,
        ?Message $message = null,
    ): ChangeSet {
        return match ($type) {
            'term' => $this->proposeTermUpdate($conversation, $resourceId, $site, $patch, $user, $summary, $message),
            'global' => $this->proposeGlobalUpdate($conversation, $resourceId, $site, $patch, $user, $summary, $message),
            'navigation' => $this->proposeNavigationUpdate($conversation, $resourceId, $site, $patch, $user, $summary, $message),
            default => throw new ContentOperationDenied("Unsupported staged resource type [{$type}]."),
        };
    }

    /** @param  array<string, mixed>  $data */
    public function proposeTermCreate(
        Conversation $conversation,
        string $taxonomyHandle,
        string $blueprintHandle,
        string $siteHandle,
        string $slug,
        array $data,
        User $user,
        ?string $summary = null,
        ?Message $message = null,
    ): ChangeSet {
        [$taxonomy, $blueprint, $site, $slug, $values] = $this->validateTermCreate(
            $taxonomyHandle,
            $blueprintHandle,
            $siteHandle,
            $slug,
            $data,
        );

        if (! $user->can('create', [TermContract::class, $taxonomy, $site])) {
            throw new ContentOperationDenied("The requesting user is not allowed to create terms in [{$taxonomy->handle()}].");
        }
        $resourceId = $taxonomy->handle().'::'.$slug;
        $after = [
            'resource_type' => 'term',
            'resource_id' => $resourceId,
            'source' => $taxonomy->handle(),
            'site' => $site->handle(),
            'blueprint' => $blueprint->handle(),
            'slug' => $slug,
            'title' => $values['title'] ?? $slug,
            'data' => $values,
            'resolved_data' => $values,
        ];

        $changeSet = $conversation->changeSets()->create([
            'proposed_by_message_id' => $message?->id,
            'status' => 'draft',
            'operation' => 'create',
            'resource_type' => 'term',
            'resource_id' => $resourceId,
            'collection' => $taxonomy->handle(),
            'site' => $site->handle(),
            'blueprint' => $blueprint->handle(),
            'slug' => $slug,
            'patch' => $data,
            'after' => $after,
            'draft_fingerprint' => $this->catalog->fingerprint($after),
            'summary' => $summary,
            'applied_at' => now(),
        ]);

        ChangeSetPrepared::dispatch($changeSet);

        return $changeSet;
    }

    public function publish(ChangeSet $changeSet, User $user): ChangeSet
    {
        if ($changeSet->status !== 'draft' || ! in_array($changeSet->resource_type, ['term', 'global', 'navigation'], true)) {
            throw new ContentOperationDenied("This staged change cannot be published from its current [{$changeSet->status}] state.");
        }

        return match ($changeSet->resource_type) {
            'term' => $changeSet->operation === 'create'
                ? $this->publishTermCreate($changeSet, $user)
                : $this->publishTermUpdate($changeSet, $user),
            'global' => $this->publishGlobalUpdate($changeSet, $user),
            'navigation' => $this->publishNavigationUpdate($changeSet, $user),
        };
    }

    /**
     * Rebuild a database-staged draft after field-level review.
     *
     * @param  array<string, mixed>  $patch
     */
    public function reviseDraft(ChangeSet $changeSet, array $patch, User $user): ChangeSet
    {
        if ($changeSet->status !== 'draft' || ! in_array($changeSet->resource_type, ['term', 'global', 'navigation'], true)) {
            throw new ContentOperationDenied("This staged change cannot be reviewed from its current [{$changeSet->status}] state.");
        }

        $before = (array) $changeSet->before;

        if ($changeSet->resource_type === 'term' && $changeSet->operation === 'create') {
            [$taxonomy, $blueprint, $site, $slug, $values] = $this->validateTermCreate(
                (string) $changeSet->collection,
                (string) $changeSet->blueprint,
                (string) $changeSet->site,
                (string) $changeSet->slug,
                $patch,
            );

            if (! $user->can('create', [TermContract::class, $taxonomy, $site])) {
                throw new ContentOperationDenied("The requesting user is not allowed to create terms in [{$taxonomy->handle()}].");
            }

            $after = [
                ...(array) $changeSet->after,
                'title' => $values['title'] ?? $slug,
                'data' => $values,
                'resolved_data' => $values,
                'blueprint' => $blueprint->handle(),
            ];
        } elseif ($changeSet->resource_type === 'term') {
            $term = Term::find((string) $changeSet->resource_id)
                ?? throw new ContentOperationDenied('The reviewed term no longer exists.');
            $localized = $term->in((string) $changeSet->site);
            $this->authorize($user, 'edit', $localized);
            $data = $this->blueprintValues->mergeAndValidate(
                $localized->blueprint(),
                ['slug' => $localized->slug(), ...(array) ($before['resolved_data'] ?? [])],
                $patch,
                ['slug'],
                ['title' => ['required']],
                (array) ($before['data'] ?? []),
            );
            $resolved = array_replace(
                (array) ($before['resolved_data'] ?? []),
                array_intersect_key($data, $patch),
            );
            $after = [
                ...$before,
                'title' => $resolved['title'] ?? ($before['title'] ?? ''),
                'data' => $data,
                'resolved_data' => $resolved,
            ];
        } elseif ($changeSet->resource_type === 'global') {
            $set = GlobalSet::findByHandle((string) $changeSet->collection)
                ?? throw new ContentOperationDenied('The reviewed global set no longer exists.');
            $variables = $set->in((string) $changeSet->site);
            $this->authorize($user, 'edit', $variables);
            $data = $this->blueprintValues->mergeAndValidate(
                $variables->blueprint(),
                (array) ($before['resolved_data'] ?? []),
                $patch,
                storageExisting: (array) ($before['data'] ?? []),
            );
            $after = [
                ...$before,
                'data' => $data,
                'resolved_data' => array_replace(
                    (array) ($before['resolved_data'] ?? []),
                    array_intersect_key($data, $patch),
                ),
            ];
        } else {
            $nav = Nav::findByHandle((string) $changeSet->collection)
                ?? throw new ContentOperationDenied('The reviewed navigation no longer exists.');
            $tree = $nav->in((string) $changeSet->site);
            $this->authorize($user, 'edit', $tree);
            $reviewedTree = array_key_exists('tree', $patch)
                ? $this->normalizeNavigationTree(
                    $nav,
                    (string) $changeSet->site,
                    (array) $patch['tree'],
                    (array) ($before['tree'] ?? []),
                    $user,
                )
                : (array) ($before['tree'] ?? []);
            $patch = ['tree' => $reviewedTree];
            $after = [...$before, 'tree' => $reviewedTree];
        }

        $changeSet->update([
            'patch' => $patch,
            'after' => $after,
            'draft_fingerprint' => $this->catalog->fingerprint($after),
            'failure' => null,
        ]);

        return $changeSet->fresh();
    }

    /** @param  array<string, mixed>  $patch */
    private function proposeTermUpdate(
        Conversation $conversation,
        string $resourceId,
        string $site,
        array $patch,
        User $user,
        ?string $summary,
        ?Message $message,
    ): ChangeSet {
        $before = $this->catalog->read($user, 'term', $resourceId, $site);
        $term = Term::find($resourceId);
        $localized = $term->in($site);
        $this->authorize($user, 'edit', $localized);
        $data = $this->blueprintValues->mergeAndValidate(
            $localized->blueprint(),
            ['slug' => $localized->slug(), ...(array) $before['resolved_data']],
            $patch,
            ['slug'],
            ['title' => ['required']],
            (array) $before['data'],
        );
        $resolvedData = array_replace((array) $before['resolved_data'], array_intersect_key($data, $patch));
        $after = [
            ...Arr::except($before, ['fingerprint']),
            'title' => $resolvedData['title'] ?? $before['title'],
            'data' => $data,
            'resolved_data' => $resolvedData,
        ];

        return $this->storeStagedUpdate($conversation, $before, $after, $patch, $summary, $message);
    }

    /** @param  array<string, mixed>  $patch */
    private function proposeGlobalUpdate(
        Conversation $conversation,
        string $resourceId,
        string $site,
        array $patch,
        User $user,
        ?string $summary,
        ?Message $message,
    ): ChangeSet {
        $before = $this->catalog->read($user, 'global', $resourceId, $site);
        $set = GlobalSet::findByHandle((string) $before['source']);
        $variables = $set->in($site);
        $this->authorize($user, 'edit', $variables);
        $data = $this->blueprintValues->mergeAndValidate(
            $variables->blueprint(),
            (array) $before['resolved_data'],
            $patch,
            storageExisting: (array) $before['data'],
        );
        $resolvedData = array_replace((array) $before['resolved_data'], array_intersect_key($data, $patch));
        $after = [
            ...Arr::except($before, ['fingerprint']),
            'data' => $data,
            'resolved_data' => $resolvedData,
        ];

        return $this->storeStagedUpdate($conversation, $before, $after, $patch, $summary, $message);
    }

    /** @param  array<string, mixed>  $patch */
    private function proposeNavigationUpdate(
        Conversation $conversation,
        string $resourceId,
        string $site,
        array $patch,
        User $user,
        ?string $summary,
        ?Message $message,
    ): ChangeSet {
        if (array_keys($patch) !== ['tree'] || ! is_array($patch['tree']) || ! array_is_list($patch['tree'])) {
            throw new ContentOperationDenied('A navigation draft must contain exactly one full tree array.');
        }

        $before = $this->catalog->read($user, 'navigation', $resourceId, $site);
        $nav = Nav::findByHandle((string) $before['source']);
        $this->authorize($user, 'edit', $nav->in($site));
        $tree = $this->normalizeNavigationTree($nav, $site, $patch['tree'], (array) $before['tree'], $user);
        $after = [...Arr::except($before, ['fingerprint']), 'tree' => $tree];

        return $this->storeStagedUpdate($conversation, $before, $after, ['tree' => $tree], $summary, $message);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $patch
     */
    private function storeStagedUpdate(
        Conversation $conversation,
        array $before,
        array $after,
        array $patch,
        ?string $summary,
        ?Message $message,
    ): ChangeSet {
        $changeSet = $conversation->changeSets()->create([
            'proposed_by_message_id' => $message?->id,
            'status' => 'draft',
            'operation' => 'update',
            'resource_type' => $before['resource_type'],
            'resource_id' => $before['resource_id'],
            'collection' => $before['source'],
            'site' => $before['site'],
            'blueprint' => $before['blueprint'] ?? null,
            'slug' => $before['slug'] ?? null,
            'base_fingerprint' => $before['fingerprint'],
            'draft_fingerprint' => $this->catalog->fingerprint($after),
            'before' => Arr::except($before, ['fingerprint']),
            'patch' => $patch,
            'after' => $after,
            'summary' => $summary,
            'applied_at' => now(),
        ]);

        ChangeSetPrepared::dispatch($changeSet);

        return $changeSet;
    }

    private function publishTermUpdate(ChangeSet $changeSet, User $user): ChangeSet
    {
        [$current, $alreadyApplied] = $this->publicationState($changeSet, $user);
        $term = Term::find((string) $changeSet->resource_id);
        $localized = $term->in((string) $changeSet->site);
        $this->authorize($user, 'edit', $localized);
        $this->pathGuard->ensure($localized->path());

        if ($alreadyApplied) {
            return $this->markPublished($changeSet, true, '');
        }

        $data = $this->blueprintValues->mergeAndValidate(
            $localized->blueprint(),
            ['slug' => $localized->slug(), ...(array) $current['resolved_data']],
            (array) $changeSet->patch,
            ['slug'],
            ['title' => ['required']],
            (array) $current['data'],
        );
        $saved = $localized->data($data)->updateLastModified($user)->save();

        return $this->markPublished($changeSet, $saved, 'Statamic refused to save the term.');
    }

    private function publishGlobalUpdate(ChangeSet $changeSet, User $user): ChangeSet
    {
        [$current, $alreadyApplied] = $this->publicationState($changeSet, $user);
        $set = GlobalSet::findByHandle((string) $changeSet->collection);
        $variables = $set->in((string) $changeSet->site);
        $this->authorize($user, 'edit', $variables);
        $this->pathGuard->ensure($variables->path());

        if ($alreadyApplied) {
            return $this->markPublished($changeSet, true, '');
        }

        $data = $this->blueprintValues->mergeAndValidate(
            $variables->blueprint(),
            (array) $current['resolved_data'],
            (array) $changeSet->patch,
            storageExisting: (array) $current['data'],
        );
        $saved = $variables->data($data)->save();

        return $this->markPublished($changeSet, $saved, 'Statamic refused to save the global values.');
    }

    private function publishNavigationUpdate(ChangeSet $changeSet, User $user): ChangeSet
    {
        [$current, $alreadyApplied] = $this->publicationState($changeSet, $user);
        $nav = Nav::findByHandle((string) $changeSet->collection);
        $tree = $nav->in((string) $changeSet->site);
        $this->authorize($user, 'edit', $tree);
        $this->pathGuard->ensure($tree->path());

        if ($alreadyApplied) {
            return $this->markPublished($changeSet, true, '');
        }
        $normalized = $this->normalizeNavigationTree(
            $nav,
            (string) $changeSet->site,
            (array) data_get($changeSet->patch, 'tree', []),
            (array) $current['tree'],
            $user,
        );
        $saved = $tree->tree($normalized)->save();

        return $this->markPublished($changeSet, $saved, 'Statamic refused to save the navigation tree.');
    }

    private function publishTermCreate(ChangeSet $changeSet, User $user): ChangeSet
    {
        if ($existing = Term::find((string) $changeSet->resource_id)) {
            $taxonomy = Taxonomy::findByHandle($existing->taxonomyHandle())
                ?? throw new ContentOperationDenied("Taxonomy [{$existing->taxonomyHandle()}] was not found.");
            $site = Site::get((string) $changeSet->site);

            if (! $site || ! $user->can('create', [TermContract::class, $taxonomy, $site])) {
                throw new ContentOperationDenied("The requesting user is not allowed to create terms in [{$taxonomy->handle()}].");
            }

            $current = $this->catalog->read($user, 'term', (string) $changeSet->resource_id, (string) $changeSet->site);

            if ($changeSet->draft_fingerprint && hash_equals((string) $changeSet->draft_fingerprint, (string) $current['fingerprint'])) {
                return $this->markPublished($changeSet, true, '');
            }

            throw new ContentConflict("Term [{$changeSet->resource_id}] was created after Secretary prepared this draft.");
        }

        [$taxonomy, $blueprint, $site, $slug, $values] = $this->validateTermCreate(
            (string) $changeSet->collection,
            (string) $changeSet->blueprint,
            (string) $changeSet->site,
            (string) $changeSet->slug,
            (array) $changeSet->patch,
        );

        if (! $user->can('create', [TermContract::class, $taxonomy, $site])) {
            throw new ContentOperationDenied("The requesting user is not allowed to create terms in [{$taxonomy->handle()}].");
        }

        $localized = Term::make()
            ->taxonomy($taxonomy)
            ->blueprint($blueprint->handle())
            ->in($site->handle());

        $defaultSite = $taxonomy->sites()->first();

        if ($site->handle() !== $defaultSite) {
            $localized->in($defaultSite)->published(true)->data($values)->slug($slug);
        }

        $localized->published(true)->data($values)->slug($slug);
        $this->pathGuard->ensure($localized->path());
        $saved = $localized->updateLastModified($user)->save();

        return $this->markPublished($changeSet, $saved, 'Statamic refused to create the term.');
    }

    /** @return array<string, mixed> */
    /** @return array{0: array<string, mixed>, 1: bool} */
    private function publicationState(ChangeSet $changeSet, User $user): array
    {
        $current = $this->catalog->read(
            $user,
            (string) $changeSet->resource_type,
            (string) $changeSet->resource_id,
            (string) $changeSet->site,
        );

        if (hash_equals((string) $changeSet->base_fingerprint, (string) $current['fingerprint'])) {
            return [$current, false];
        }

        if ($changeSet->draft_fingerprint && hash_equals((string) $changeSet->draft_fingerprint, (string) $current['fingerprint'])) {
            return [$current, true];
        }

        throw new ContentConflict('The content changed after Secretary prepared this draft. Nothing was published.');
    }

    private function markPublished(ChangeSet $changeSet, mixed $saved, string $failure): ChangeSet
    {
        if ($saved === false) {
            throw new ContentOperationDenied($failure);
        }

        $changeSet->update([
            'status' => 'published',
            'published_at' => now(),
            'failure' => null,
        ]);

        return $changeSet->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: mixed, 1: mixed, 2: mixed, 3: string, 4: array<string, mixed>}
     */
    private function validateTermCreate(
        string $taxonomyHandle,
        string $blueprintHandle,
        string $siteHandle,
        string $slug,
        array $data,
    ): array {
        $this->allowedSources->ensure('taxonomy', $taxonomyHandle);
        $taxonomy = Taxonomy::findByHandle($taxonomyHandle)
            ?? throw new ContentOperationDenied("Taxonomy [{$taxonomyHandle}] was not found.");
        $this->pathGuard->ensure($taxonomy->path());
        $site = Site::get($siteHandle);

        if (! $site || ! $taxonomy->sites()->contains($siteHandle)) {
            throw new ContentOperationDenied("Site [{$siteHandle}] is not available for taxonomy [{$taxonomyHandle}].");
        }

        $blueprint = $taxonomy->termBlueprint($blueprintHandle)
            ?? throw new ContentOperationDenied("Blueprint [{$blueprintHandle}] was not found in taxonomy [{$taxonomyHandle}].");
        $slug = trim($slug);

        if ($slug === '' || $slug !== Str::slug($slug)) {
            throw new ContentOperationDenied('The term slug must be a non-empty normalized slug without path separators.');
        }

        if (Term::find($taxonomyHandle.'::'.$slug)) {
            throw new ContentOperationDenied("Term [{$taxonomyHandle}::{$slug}] already exists.");
        }

        $values = $this->blueprintValues->mergeAndValidate(
            $blueprint,
            ['slug' => $slug],
            $data,
            ['slug'],
            ['title' => ['required']],
        );
        unset($values['slug']);

        return [$taxonomy, $blueprint, $site, $slug, $values];
    }

    /**
     * @param  array<int, array<string, mixed>>  $tree
     * @param  array<int, array<string, mixed>>  $beforeTree
     * @return array<int, array<string, mixed>>
     */
    private function normalizeNavigationTree($nav, string $site, array $tree, array $beforeTree, User $user): array
    {
        $seenIds = [];
        $nodes = 0;
        $beforeEntries = $this->navigationEntryReferences($beforeTree);
        $maximumNodes = max(1, min((int) config('secretary.limits.max_navigation_nodes', 500), 5000));
        $maximumDepth = $nav->maxDepth();
        $blueprint = $nav->blueprint()
            ->ensureField('title', ['type' => 'text'])
            ->ensureField('url', ['type' => 'text']);

        $normalize = function (array $branches, int $depth) use (
            &$normalize,
            &$seenIds,
            &$nodes,
            $beforeEntries,
            $maximumNodes,
            $maximumDepth,
            $blueprint,
            $user,
        ): array {
            if (! array_is_list($branches)) {
                throw new ContentOperationDenied('Navigation children must be JSON arrays.');
            }

            if ($maximumDepth && $depth > $maximumDepth) {
                throw new ContentOperationDenied("Navigation exceeds its configured maximum depth of {$maximumDepth}.");
            }

            return collect($branches)->map(function ($branch) use (
                &$normalize,
                &$seenIds,
                &$nodes,
                $beforeEntries,
                $maximumNodes,
                $depth,
                $blueprint,
                $user,
            ): array {
                if (! is_array($branch) || array_is_list($branch)) {
                    throw new ContentOperationDenied('Every navigation node must be a JSON object.');
                }

                $invalid = array_diff(array_keys($branch), ['id', 'entry', 'title', 'url', 'data', 'children']);

                if ($invalid !== []) {
                    throw new ContentOperationDenied('Unknown navigation node keys: '.implode(', ', $invalid));
                }

                if (++$nodes > $maximumNodes) {
                    throw new ContentOperationDenied("Navigation exceeds Secretary's {$maximumNodes}-node safety limit.");
                }

                $id = isset($branch['id']) && is_string($branch['id']) && trim($branch['id']) !== ''
                    ? $branch['id']
                    : (string) Str::uuid();

                if (isset($seenIds[$id])) {
                    throw new ContentOperationDenied("Navigation node ID [{$id}] is duplicated.");
                }

                $seenIds[$id] = true;
                $entryId = $branch['entry'] ?? null;
                $title = $branch['title'] ?? null;
                $url = $branch['url'] ?? null;
                $data = $branch['data'] ?? [];

                if ($entryId !== null && (! is_string($entryId) || $entryId === '')) {
                    throw new ContentOperationDenied('Navigation entry references must be non-empty strings.');
                }

                if ($title !== null && ! is_string($title)) {
                    throw new ContentOperationDenied('Navigation titles must be strings or null.');
                }

                if ($url !== null && (! is_string($url) || $this->unsafeNavigationUrl($url))) {
                    throw new ContentOperationDenied('Navigation URLs must be relative or use http, https, mailto, or tel.');
                }

                if (! is_array($data) || ($data !== [] && array_is_list($data)) || array_key_exists('title', $data) || array_key_exists('url', $data)) {
                    throw new ContentOperationDenied('Navigation node data must be an object and may not duplicate title or url.');
                }

                if ($entryId === null && blank($title) && blank($url)) {
                    throw new ContentOperationDenied('A navigation node needs an entry, title, or URL.');
                }

                if ($entryId !== null) {
                    $entry = Entry::find($entryId)
                        ?? throw new ContentOperationDenied("Navigation entry [{$entryId}] was not found.");
                    $this->pathGuard->ensure($entry->path());

                    if (! in_array($entryId, $beforeEntries, true)) {
                        $this->allowedCollections->ensure($entry->collection()->handle());

                        if (! $user->can('view', $entry)) {
                            throw new ContentOperationDenied("The requesting user is not allowed to reference entry [{$entryId}] in navigation.");
                        }
                    }
                }

                $values = $this->blueprintValues->mergeAndValidate(
                    $blueprint,
                    [],
                    ['title' => $title, 'url' => $url, ...$data],
                );
                $normalized = array_filter([
                    'id' => $id,
                    'entry' => $entryId,
                    'title' => Arr::pull($values, 'title'),
                    'url' => Arr::pull($values, 'url'),
                    'data' => Arr::removeNullValues($values),
                ], static fn (mixed $value, string $key): bool => $key === 'id' || ($key === 'data' ? $value !== [] : $value !== null), ARRAY_FILTER_USE_BOTH);
                $children = $branch['children'] ?? [];

                if (! is_array($children)) {
                    throw new ContentOperationDenied('Navigation children must be arrays.');
                }

                if ($children !== []) {
                    $normalized['children'] = $normalize($children, $depth + 1);
                }

                return $normalized;
            })->all();
        };

        $normalized = $normalize($tree, 1);

        return $nav->validateTree($normalized, $site);
    }

    /** @param  array<int, array<string, mixed>>  $tree */
    private function navigationEntryReferences(array $tree): array
    {
        $references = [];

        foreach ($tree as $branch) {
            if (isset($branch['entry']) && is_string($branch['entry'])) {
                $references[] = $branch['entry'];
            }

            if (isset($branch['children']) && is_array($branch['children'])) {
                $references = [...$references, ...$this->navigationEntryReferences($branch['children'])];
            }
        }

        return array_values(array_unique($references));
    }

    private function unsafeNavigationUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || ! preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return false;
        }

        return ! preg_match('/^(https?|mailto|tel):/i', $url);
    }

    private function authorize(User $user, string $ability, mixed $resource): void
    {
        if (! $user->can($ability, $resource)) {
            throw new ContentOperationDenied("The requesting user is not allowed to {$ability} this content.");
        }
    }
}
