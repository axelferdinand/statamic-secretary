<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use AxelFerdinand\StatamicSecretary\Database\SecretaryDatabase;
use AxelFerdinand\StatamicSecretary\Models\Setting;
use Statamic\Facades\Collection;
use Statamic\Statamic;
use Throwable;

final class SafeDrafting
{
    private const SETTING_KEY = 'content_safety';

    public function __construct(
        private readonly AllowedCollections $allowedCollections,
        private readonly SecretaryDatabase $database,
    ) {}

    public function applyManagedConfiguration(): void
    {
        if ($this->managedBySecretary()) {
            config()->set('statamic.revisions.enabled', true);
        }
    }

    /** @return array{ready: bool, pro: bool, global_enabled: bool, disabled_collections: array<int, string>, details: string, success_details: string} */
    public function status(): array
    {
        $this->applyManagedConfiguration();

        $pro = Statamic::pro();
        $globalEnabled = (bool) config('statamic.revisions.enabled');
        $collectionsReadable = true;

        try {
            $disabledCollections = $this->managedCollections()
                ->filter(fn ($collection): bool => ! (bool) data_get($collection->fileData(), 'revisions', false))
                ->map(fn ($collection): string => (string) ($collection->title() ?: $collection->handle()))
                ->values()
                ->all();
        } catch (Throwable) {
            $collectionsReadable = false;
            $disabledCollections = [];
        }

        $ready = $pro && $globalEnabled && $collectionsReadable && $disabledCollections === [];

        if (! $pro) {
            $details = 'Safe drafts require Statamic Pro because they use Statamic revisions.';
        } elseif (! $globalEnabled) {
            $details = 'Enable safe drafts so Secretary can prepare changes without touching the live page.';
        } elseif (! $collectionsReadable) {
            $details = 'Secretary could not inspect the allowed collections. Check content access, then run the checks again.';
        } elseif ($disabledCollections !== []) {
            $details = 'Enable safe drafts for: '.implode(', ', $disabledCollections).'.';
        } else {
            $details = '';
        }

        return [
            'ready' => $ready,
            'pro' => $pro,
            'global_enabled' => $globalEnabled,
            'disabled_collections' => $disabledCollections,
            'details' => $details,
            'success_details' => 'Working copies keep published content unchanged until you approve and publish.',
        ];
    }

    /** @return array{ready: bool, pro: bool, global_enabled: bool, disabled_collections: array<int, string>, details: string, success_details: string} */
    public function enable(): array
    {
        if (! Statamic::pro()) {
            return $this->status();
        }

        config()->set('statamic.revisions.enabled', true);

        $this->managedCollections()->each(function ($collection): void {
            if ((bool) data_get($collection->fileData(), 'revisions', false)) {
                return;
            }

            if ($collection->revisionsEnabled(true)->save() === false) {
                throw new \RuntimeException("Statamic refused to enable revisions for [{$collection->handle()}].");
            }
        });

        Setting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => ['managed_revisions' => true, 'enabled_at' => now()->toIso8601String()]],
        );

        return $this->status();
    }

    private function managedBySecretary(): bool
    {
        try {
            if (! $this->database->schema()->hasTable('secretary_settings')) {
                return false;
            }

            return data_get(Setting::query()->find(self::SETTING_KEY)?->value, 'managed_revisions') === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function managedCollections()
    {
        return Collection::all()
            ->filter(fn ($collection): bool => $this->allowedCollections->allows((string) $collection->handle()))
            ->values();
    }
}
