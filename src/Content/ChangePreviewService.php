<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use AxelFerdinand\StatamicSecretary\Models\ChangeSet;
use Facades\Statamic\CP\LivePreview;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\Entry;
use Statamic\Facades\URL;
use Statamic\Support\Str;

final class ChangePreviewService
{
    public function __construct(private readonly EntrySnapshotter $snapshotter) {}

    /** @return array{live_url: string|null, draft_url: string, title: string} */
    public function urls(ChangeSet $changeSet, User $user): array
    {
        if ($changeSet->resource_type !== 'entry' || $changeSet->status !== 'draft') {
            throw new ContentOperationDenied('Live comparison is available only for active entry drafts.');
        }

        $entry = Entry::find((string) $changeSet->resource_id)
            ?? throw new ContentOperationDenied('The entry for this draft no longer exists.');

        if (! $user->can('view', $entry)) {
            throw new ContentOperationDenied('You are not allowed to preview this entry.');
        }

        $draft = $this->snapshotter->authoringEntry($entry);
        $target = $draft->previewTargets()->first();

        if (! is_array($target) || blank($target['url'] ?? null)) {
            throw new ContentOperationDenied('This collection has no Statamic preview target.');
        }

        $token = LivePreview::tokenize(null, $draft)->token();
        $url = URL::makeAbsolute((string) $target['url']);
        $draftUrl = vsprintf('%s%slive-preview=%s&token=%s', [
            $url,
            str_contains($url, '?') ? '&' : '?',
            Str::random(),
            $token,
        ]);

        return [
            'live_url' => $entry->published() ? $entry->absoluteUrl() : null,
            'draft_url' => $draftUrl,
            'title' => (string) ($draft->get('title') ?: $draft->slug()),
        ];
    }
}
