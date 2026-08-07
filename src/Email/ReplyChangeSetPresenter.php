<?php

namespace AxelFerdinand\StatamicSecretary\Email;

use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Models\Message;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Throwable;

final class ReplyChangeSetPresenter
{
    /**
     * @return array<int, array{
     *     id: string,
     *     status: string,
     *     summary: string,
     *     native_url: string|null,
     *     resource_title: string|null,
     *     public_url: string|null
     * }>
     */
    public function present(Conversation $conversation, Message $reply): array
    {
        $user = User::find($conversation->user_id);

        $presented = $conversation->changeSets()
            ->whereIn('id', (array) data_get($reply->metadata, 'change_set_ids', []))
            ->get()
            ->map(fn ($change): array => $this->presentChange($change, $user, $conversation))
            ->values()
            ->all();
        $affectedIndexes = array_keys(array_filter(
            $presented,
            static fn (array $changeSet): bool => is_string($changeSet['public_url'] ?? null),
        ));
        $bodyLabel = $this->affectedPageLabel($reply->body);

        if (count($affectedIndexes) === 1 && $bodyLabel !== null) {
            $presented[$affectedIndexes[0]]['resource_title'] = $bodyLabel;
        }

        return $presented;
    }

    public function conversationUrl(Conversation $conversation): string
    {
        return cp_route('secretary.show', $conversation);
    }

    /** @param  array<int, array<string, mixed>>  $changeSets */
    public function emailBody(string $body, array $changeSets): string
    {
        $affected = array_values(array_filter(
            $changeSets,
            static fn (array $changeSet): bool => is_string($changeSet['public_url'] ?? null),
        ));

        if (count($affected) !== 1) {
            return trim($body);
        }

        return trim((string) preg_replace(
            '/^(?:Berørt side|Affected page):\s*.*(?:\R|$)/miu',
            '',
            $body,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $changeSets
     * @return array{before: string, after: string}
     */
    public function emailBodySections(string $body, array $changeSets): array
    {
        $cleaned = $this->emailBody($body, $changeSets);
        $affected = array_values(array_filter(
            $changeSets,
            static fn (array $changeSet): bool => is_string($changeSet['public_url'] ?? null),
        ));

        if (count($affected) !== 1
            || preg_match('/^(Status:\s*.+)$/miu', $cleaned, $status, PREG_OFFSET_CAPTURE) !== 1) {
            return ['before' => $cleaned, 'after' => ''];
        }

        $offset = (int) $status[0][1];

        return [
            'before' => trim(substr($cleaned, 0, $offset)),
            'after' => trim(substr($cleaned, $offset)),
        ];
    }

    private function presentChange($change, $user, Conversation $conversation): array
    {
        if (! $user
            || ! in_array($change->status, ['draft', 'published'], true)) {
            return $this->unlinkedChange($change);
        }

        try {
            $resource = match ($change->resource_type) {
                'entry' => Entry::find((string) $change->resource_id),
                'term' => Term::find((string) $change->resource_id)?->in((string) $change->site),
                'global' => GlobalSet::findByHandle((string) $change->collection)?->in((string) $change->site),
                'navigation' => Nav::findByHandle((string) $change->collection)?->in((string) $change->site),
                default => null,
            };

            if (! $resource || ! $user->can('view', $resource)) {
                throw new \RuntimeException('Content resource is not available to this user.');
            }

            $nativeUrl = $resource->editUrl();

            if ($change->resource_type === 'entry' && $change->status === 'draft') {
                $nativeUrl .= (str_contains($nativeUrl, '?') ? '&' : '?')
                    .'secretary='.rawurlencode((string) $conversation->id);
            }

            return [
                'id' => (string) $change->id,
                'status' => (string) $change->status,
                'summary' => (string) ($change->summary ?: $change->resource_id),
                'native_url' => $nativeUrl,
                'resource_title' => $this->resourceTitle($change, $resource),
                'public_url' => $change->resource_type === 'entry' ? $resource->absoluteUrl() : null,
            ];
        } catch (Throwable) {
            return $this->unlinkedChange($change);
        }
    }

    private function resourceTitle($change, $resource): string
    {
        $title = match ($change->resource_type) {
            'entry' => (string) ($resource->get('title') ?: $resource->slug()),
            'term' => (string) $resource->title(),
            'global' => (string) (GlobalSet::findByHandle((string) $change->collection)?->title()
                ?: $change->collection),
            'navigation' => (string) (Nav::findByHandle((string) $change->collection)?->title()
                ?: $change->collection),
            default => '',
        };

        return trim($title) ?: (string) ($change->summary ?: $change->resource_id);
    }

    private function unlinkedChange($change): array
    {
        return [
            'id' => (string) $change->id,
            'status' => (string) $change->status,
            'summary' => (string) ($change->summary ?: $change->resource_id),
            'native_url' => null,
            'resource_title' => null,
            'public_url' => null,
        ];
    }

    private function affectedPageLabel(string $body): ?string
    {
        if (preg_match('/^(?:Berørt side|Affected page):\s*(.+)$/miu', $body, $matches) !== 1) {
            return null;
        }

        $label = trim((string) preg_replace('/\s+\([^)]*\)\s*$/u', '', $matches[1]));

        return $label !== '' && mb_strlen($label) <= 500 ? $label : null;
    }
}
