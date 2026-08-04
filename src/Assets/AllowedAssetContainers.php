<?php

namespace AxelFerdinand\StatamicSecretary\Assets;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\AssetContainer;

final class AllowedAssetContainers
{
    public function enabled(): bool
    {
        return (bool) config('secretary.assets.enabled', true);
    }

    public function allows(string $handle): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $configured = $this->configured();

        return $configured === [] || in_array($handle, $configured, true);
    }

    public function ensure(string $handle): void
    {
        if (! $this->allows($handle)) {
            throw new ContentOperationDenied("Asset container [{$handle}] is outside Secretary's configured boundary.");
        }
    }

    public function uploadContainer(User $user): mixed
    {
        if (! $this->enabled()) {
            throw new ContentOperationDenied('Secretary asset access is disabled.');
        }

        $configuredHandle = trim((string) config('secretary.assets.attachment_container'));

        if ($configuredHandle !== '') {
            $this->ensure($configuredHandle);
            $container = AssetContainer::find($configuredHandle)
                ?? throw new ContentOperationDenied("Asset container [{$configuredHandle}] was not found.");
            $this->authorizeUpload($user, $container);

            return $container;
        }

        $eligible = AssetContainer::all()
            ->filter(fn ($container): bool => $this->allows($container->handle()))
            ->filter(fn ($container): bool => $user->can('store', [AssetContract::class, $container]))
            ->values();

        if ($eligible->count() !== 1) {
            throw new ContentOperationDenied(
                $eligible->isEmpty()
                    ? 'The requesting user cannot upload to an allowed Statamic asset container.'
                    : 'Configure SECRETARY_ATTACHMENT_CONTAINER because more than one uploadable asset container is available.',
            );
        }

        return $eligible->first();
    }

    /** @return array<int, string> */
    public function configured(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $handle): string => trim((string) $handle),
            (array) config('secretary.assets.containers', []),
        ))));
    }

    private function authorizeUpload(User $user, mixed $container): void
    {
        if (! $user->can('store', [AssetContract::class, $container])) {
            throw new ContentOperationDenied("The requesting user cannot upload to asset container [{$container->handle()}].");
        }
    }
}
