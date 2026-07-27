<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentBoundaryViolation;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

final class ContentResourceCatalog
{
    public function __construct(
        private readonly AllowedContentSources $allowedSources,
        private readonly ContentPathGuard $pathGuard,
        private readonly BlueprintDescriber $blueprints,
        private readonly ContentPayloadGuard $payloadGuard,
    ) {}

    /** @return array<string, array<int, array<string, mixed>>> */
    public function sources(User $user): array
    {
        return [
            'taxonomies' => Taxonomy::all()
                ->filter(fn ($taxonomy): bool => $this->isAllowedPath('taxonomy', $taxonomy->handle(), $taxonomy->path()))
                ->filter(fn ($taxonomy): bool => $user->can('view', $taxonomy))
                ->map(fn ($taxonomy): array => [
                    'handle' => $taxonomy->handle(),
                    'title' => $taxonomy->title(),
                    'sites' => $taxonomy->sites()->values()->all(),
                    'blueprints' => $taxonomy->termBlueprints()->map->handle()->values()->all(),
                ])->values()->all(),
            'global_sets' => GlobalSet::all()
                ->filter(fn ($set): bool => $this->isAllowedPath('global', $set->handle(), $set->path()))
                ->filter(fn ($set): bool => $user->can('view', $set))
                ->map(fn ($set): array => [
                    'handle' => $set->handle(),
                    'title' => $set->title(),
                    'sites' => $set->sites()->values()->all(),
                    'blueprint' => $set->blueprint()?->handle(),
                ])->values()->all(),
            'navigations' => Nav::all()
                ->filter(fn ($nav): bool => $this->isAllowedPath('navigation', $nav->handle(), $nav->path()))
                ->filter(fn ($nav): bool => $user->can('view', $nav))
                ->map(fn ($nav): array => [
                    'handle' => $nav->handle(),
                    'title' => $nav->title(),
                    'sites' => $nav->sites()->values()->all(),
                    'max_depth' => $nav->maxDepth(),
                ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function describe(User $user, string $type, string $source, ?string $blueprint = null): array
    {
        $description = match ($type) {
            'term' => $this->describeTerm($user, $source, $blueprint),
            'global' => $this->describeGlobal($user, $source),
            'navigation' => $this->describeNavigation($user, $source),
            default => throw new ContentOperationDenied("Unsupported content resource type [{$type}]."),
        };

        return $this->payloadGuard->ensure($description, ucfirst($type)." schema [{$source}]");
    }

    /** @return array<int, array<string, mixed>> */
    public function search(User $user, string $type, string $query, ?string $source = null, ?string $site = null): array
    {
        $limit = max(1, min((int) config('secretary.content.max_search_results', 20), 100));
        $needle = mb_strtolower(trim($query));

        return collect(match ($type) {
            'term' => $this->searchTerms($user, $source, $site),
            'global' => $this->searchGlobals($user, $source, $site),
            'navigation' => $this->searchNavigations($user, $source, $site),
            default => throw new ContentOperationDenied("Unsupported content resource type [{$type}]."),
        })->filter(function (array $item) use ($needle): bool {
            if ($needle === '') {
                return true;
            }

            return str_contains(mb_strtolower(implode(' ', array_filter([
                $item['resource_id'] ?? null,
                $item['source'] ?? null,
                $item['slug'] ?? null,
                $item['title'] ?? null,
            ], 'is_string'))), $needle);
        })->take($limit)->values()->all();
    }

    /** @return array<string, mixed> */
    public function read(User $user, string $type, string $resourceId, ?string $site = null): array
    {
        $snapshot = match ($type) {
            'term' => $this->termSnapshot($user, $resourceId, $site),
            'global' => $this->globalSnapshot($user, $resourceId, $site),
            'navigation' => $this->navigationSnapshot($user, $resourceId, $site),
            default => throw new ContentOperationDenied("Unsupported content resource type [{$type}]."),
        };
        $snapshot['fingerprint'] = $this->fingerprint($snapshot);

        return $this->payloadGuard->ensure($snapshot, ucfirst($type)." resource [{$resourceId}]");
    }

    /** @param  array<string, mixed>  $snapshot */
    public function fingerprint(array $snapshot): string
    {
        unset($snapshot['fingerprint']);
        $this->sortRecursively($snapshot);

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    private function describeTerm(User $user, string $taxonomyHandle, ?string $blueprintHandle): array
    {
        $taxonomy = $this->taxonomy($taxonomyHandle);
        $this->authorizeView($user, $taxonomy, "taxonomy [{$taxonomyHandle}]");
        $blueprintHandle ??= $taxonomy->termBlueprints()->first()?->handle();
        $blueprint = $taxonomy->termBlueprint($blueprintHandle)
            ?? throw new ContentOperationDenied("Blueprint [{$blueprintHandle}] was not found in taxonomy [{$taxonomyHandle}].");

        return ['resource_type' => 'term', 'source' => $taxonomyHandle, ...$this->blueprints->describe($blueprint)];
    }

    /** @return array<string, mixed> */
    private function describeGlobal(User $user, string $handle): array
    {
        $set = $this->globalSet($handle);
        $this->authorizeView($user, $set, "global set [{$handle}]");
        $variables = $set->in($set->sites()->first())
            ?? throw new ContentOperationDenied("Global set [{$handle}] has no readable site.");

        return ['resource_type' => 'global', 'source' => $handle, ...$this->blueprints->describe($variables->blueprint())];
    }

    /** @return array<string, mixed> */
    private function describeNavigation(User $user, string $handle): array
    {
        $nav = $this->navigation($handle);
        $this->authorizeView($user, $nav, "navigation [{$handle}]");
        $blueprint = $nav->blueprint()
            ->ensureField('title', ['type' => 'text'])
            ->ensureField('url', ['type' => 'text']);

        return [
            'resource_type' => 'navigation',
            'source' => $handle,
            'max_depth' => $nav->maxDepth(),
            'tree_node_shape' => ['id', 'entry', 'title', 'url', 'data', 'children'],
            ...$this->blueprints->describe($blueprint),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function searchTerms(User $user, ?string $source, ?string $site): array
    {
        $taxonomies = $source ? collect([$this->taxonomy($source)]) : Taxonomy::all()
            ->filter(fn ($taxonomy): bool => $this->isAllowedPath('taxonomy', $taxonomy->handle(), $taxonomy->path()))
            ->filter(fn ($taxonomy): bool => $user->can('view', $taxonomy));
        $taxonomies = $taxonomies->filter(fn ($taxonomy): bool => $user->can('view', $taxonomy));

        return $taxonomies->flatMap(function ($taxonomy) use ($site, $user) {
            $sites = $site ? collect([$site]) : $taxonomy->sites();

            return Term::whereTaxonomy($taxonomy->handle())->flatMap(function ($term) use ($taxonomy, $sites, $user) {
                $this->pathGuard->ensure($term->path());

                return $sites->filter(fn (string $handle): bool => $taxonomy->sites()->contains($handle) && $user->can('view', $term->in($handle)))
                    ->map(function (string $handle) use ($term, $taxonomy): array {
                        $localized = $term->in($handle);

                        return [
                            'resource_type' => 'term',
                            'resource_id' => $term->id(),
                            'source' => $taxonomy->handle(),
                            'site' => $handle,
                            'slug' => $localized->slug(),
                            'title' => $localized->title(),
                        ];
                    });
            });
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function searchGlobals(User $user, ?string $source, ?string $site): array
    {
        $sets = $source ? collect([$this->globalSet($source)]) : GlobalSet::all()
            ->filter(fn ($set): bool => $this->isAllowedPath('global', $set->handle(), $set->path()))
            ->filter(fn ($set): bool => $user->can('view', $set));
        $sets = $sets->filter(fn ($set): bool => $user->can('view', $set));

        return $sets->flatMap(function ($set) use ($site, $user) {
            $sites = $site ? collect([$site]) : $set->sites();

            return $sites->filter(fn (string $handle): bool => $set->sites()->contains($handle) && $user->can('view', $set->in($handle)))
                ->map(fn (string $handle): array => [
                    'resource_type' => 'global',
                    'resource_id' => $set->handle().'::'.$handle,
                    'source' => $set->handle(),
                    'site' => $handle,
                    'title' => $set->title(),
                ]);
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function searchNavigations(User $user, ?string $source, ?string $site): array
    {
        $navs = $source ? collect([$this->navigation($source)]) : Nav::all()
            ->filter(fn ($nav): bool => $this->isAllowedPath('navigation', $nav->handle(), $nav->path()))
            ->filter(fn ($nav): bool => $user->can('view', $nav));
        $navs = $navs->filter(fn ($nav): bool => $user->can('view', $nav));

        return $navs->flatMap(function ($nav) use ($site, $user) {
            $sites = $site ? collect([$site]) : $nav->sites();

            return $sites->filter(fn (string $handle): bool => $nav->in($handle) !== null && $user->can('view', $nav->in($handle)))
                ->map(fn (string $handle): array => [
                    'resource_type' => 'navigation',
                    'resource_id' => $nav->handle().'::'.$handle,
                    'source' => $nav->handle(),
                    'site' => $handle,
                    'title' => $nav->title(),
                ]);
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function termSnapshot(User $user, string $resourceId, ?string $site): array
    {
        $term = Term::find($resourceId)
            ?? throw new ContentOperationDenied("Term [{$resourceId}] was not found.");
        $taxonomy = $this->taxonomy($term->taxonomyHandle());
        $site ??= $taxonomy->sites()->first();

        if (! $taxonomy->sites()->contains($site)) {
            throw new ContentOperationDenied("Site [{$site}] is not available for taxonomy [{$taxonomy->handle()}].");
        }

        $this->pathGuard->ensure($term->path());
        $localized = $term->in($site);
        $this->authorizeView($user, $localized, "term [{$resourceId}] in site [{$site}]");

        return [
            'resource_type' => 'term',
            'resource_id' => $term->id(),
            'source' => $taxonomy->handle(),
            'site' => $site,
            'blueprint' => $localized->blueprint()->handle(),
            'slug' => $localized->slug(),
            'title' => $localized->title(),
            'data' => $localized->data()->all(),
            'resolved_data' => $localized->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function globalSnapshot(User $user, string $resourceId, ?string $site): array
    {
        [$handle, $site] = $this->parseLocalizedId($resourceId, $site);
        $set = $this->globalSet($handle);
        $site ??= $set->sites()->first();
        $variables = $set->in($site)
            ?? throw new ContentOperationDenied("Global set [{$handle}] is not available in site [{$site}].");
        $this->authorizeView($user, $variables, "global set [{$handle}] in site [{$site}]");
        $this->pathGuard->ensure($variables->path());

        return [
            'resource_type' => 'global',
            'resource_id' => $handle.'::'.$site,
            'source' => $handle,
            'site' => $site,
            'blueprint' => $variables->blueprint()->handle(),
            'title' => $set->title(),
            'data' => $variables->data()->all(),
            'resolved_data' => $variables->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function navigationSnapshot(User $user, string $resourceId, ?string $site): array
    {
        [$handle, $site] = $this->parseLocalizedId($resourceId, $site);
        $nav = $this->navigation($handle);
        $site ??= $nav->sites()->first();
        $tree = $nav->in($site)
            ?? throw new ContentOperationDenied("Navigation [{$handle}] is not available in site [{$site}].");
        $this->authorizeView($user, $tree, "navigation [{$handle}] in site [{$site}]");
        $this->pathGuard->ensure($tree->path());

        return [
            'resource_type' => 'navigation',
            'resource_id' => $handle.'::'.$site,
            'source' => $handle,
            'site' => $site,
            'title' => $nav->title(),
            'tree' => $tree->tree(),
        ];
    }

    private function taxonomy(string $handle)
    {
        $this->allowedSources->ensure('taxonomy', $handle);
        $taxonomy = Taxonomy::findByHandle($handle)
            ?? throw new ContentOperationDenied("Taxonomy [{$handle}] was not found.");
        $this->pathGuard->ensure($taxonomy->path());

        return $taxonomy;
    }

    private function globalSet(string $handle)
    {
        $this->allowedSources->ensure('global', $handle);
        $set = GlobalSet::findByHandle($handle)
            ?? throw new ContentOperationDenied("Global set [{$handle}] was not found.");
        $this->pathGuard->ensure($set->path());

        return $set;
    }

    private function navigation(string $handle)
    {
        $this->allowedSources->ensure('navigation', $handle);
        $nav = Nav::findByHandle($handle)
            ?? throw new ContentOperationDenied("Navigation [{$handle}] was not found.");
        $this->pathGuard->ensure($nav->path());

        return $nav;
    }

    /** @return array{0: string, 1: string|null} */
    private function parseLocalizedId(string $resourceId, ?string $site): array
    {
        $parts = explode('::', $resourceId, 2);

        return [$parts[0], $site ?: ($parts[1] ?? null)];
    }

    private function isAllowedPath(string $type, string $handle, string $path): bool
    {
        if (! $this->allowedSources->allows($type, $handle)) {
            return false;
        }

        try {
            $this->pathGuard->ensure($path);

            return true;
        } catch (ContentBoundaryViolation) {
            return false;
        }
    }

    private function authorizeView(User $user, mixed $resource, string $label): void
    {
        if (! $user->can('view', $resource)) {
            throw new ContentOperationDenied("The requesting user is not allowed to read {$label}.");
        }
    }

    /** @param  array<string, mixed>  $value */
    private function sortRecursively(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursively($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }
    }
}
