<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentBoundaryViolation;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

final class EntryCatalog
{
    public function __construct(
        private readonly AllowedCollections $allowedCollections,
        private readonly ContentPathGuard $pathGuard,
        private readonly EntrySnapshotter $snapshotter,
        private readonly BlueprintDescriber $blueprints,
        private readonly ContentPayloadGuard $payloadGuard,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function collections(User $user): array
    {
        return Collection::all()
            ->filter(fn ($collection): bool => $this->allowedCollections->allows($collection->handle()))
            ->filter(fn ($collection): bool => $user->can('view', $collection))
            ->map(fn ($collection): array => [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'sites' => $collection->sites()->values()->all(),
                'structured' => $collection->hasStructure(),
                'revisions_enabled' => $collection->revisionsEnabled(),
                'blueprints' => $collection->entryBlueprints()->map->handle()->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function describeBlueprint(User $user, string $collectionHandle, string $blueprintHandle): array
    {
        $this->allowedCollections->ensure($collectionHandle);

        $collection = Collection::find($collectionHandle)
            ?? throw new ContentOperationDenied("Collection [{$collectionHandle}] was not found.");
        $this->authorizeView($user, $collection, "collection [{$collectionHandle}]");
        $blueprint = $collection->entryBlueprint($blueprintHandle)
            ?? throw new ContentOperationDenied("Blueprint [{$blueprintHandle}] was not found in [{$collectionHandle}].");

        return $this->payloadGuard->ensure($this->blueprints->describe($blueprint), "Blueprint [{$blueprintHandle}]");
    }

    /** @return array<string, mixed> */
    public function read(User $user, string $id): array
    {
        $entry = Entry::find($id)
            ?? throw new ContentOperationDenied("Entry [{$id}] was not found.");

        $this->allowedCollections->ensure($entry->collection()->handle());
        $this->pathGuard->ensure($entry->path());
        $this->authorizeView($user, $entry, "entry [{$id}]");

        $snapshot = $this->snapshotter->snapshot($entry);
        $snapshot['fingerprint'] = $this->snapshotter->fingerprint($snapshot);

        return $this->payloadGuard->ensure($snapshot, "Entry [{$id}]");
    }

    /** @return array<int, array<string, mixed>> */
    public function search(User $user, string $query, ?string $collectionHandle = null, ?string $site = null): array
    {
        if ($collectionHandle !== null) {
            $this->allowedCollections->ensure($collectionHandle);
        }

        $needle = mb_strtolower(trim($query));
        $limit = max(1, min((int) config('secretary.content.max_search_results', 20), 100));

        return Entry::query()
            ->when($collectionHandle, fn ($builder) => $builder->where('collection', $collectionHandle))
            ->when($site, fn ($builder) => $builder->where('site', $site))
            ->get()
            ->filter(fn ($entry): bool => $this->allowedCollections->allows($entry->collection()->handle()))
            ->filter(fn ($entry): bool => $user->can('view', $entry))
            ->filter(function ($entry): bool {
                try {
                    $this->pathGuard->ensure($entry->path());

                    return true;
                } catch (ContentBoundaryViolation) {
                    return false;
                }
            })
            ->filter(function ($entry) use ($needle): bool {
                if ($needle === '') {
                    return true;
                }

                $haystack = mb_strtolower(implode(' ', array_filter([
                    $entry->id(),
                    $entry->slug(),
                    $entry->value('title'),
                    $entry->uri(),
                ], 'is_string')));

                return str_contains($haystack, $needle);
            })
            ->take($limit)
            ->map(fn ($entry): array => [
                'id' => $entry->id(),
                'collection' => $entry->collection()->handle(),
                'site' => $entry->locale(),
                'title' => $entry->value('title'),
                'slug' => $entry->slug(),
                'uri' => $entry->uri(),
                'status' => $entry->status(),
            ])
            ->values()
            ->all();
    }

    private function authorizeView(User $user, mixed $resource, string $label): void
    {
        if (! $user->can('view', $resource)) {
            throw new ContentOperationDenied("The requesting user is not allowed to read {$label}.");
        }
    }
}
