<?php

namespace AxelFerdinand\StatamicSecretary\Email;

use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Models\Message;
use Statamic\Facades\Entry;
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
            ->map(fn ($change): array => $this->presentChange($change, $user))
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

    /** @param  array<int, array<string, mixed>>  $changeSets */
    public function conversationUrl(Conversation $conversation, array $changeSets): string
    {
        $nativeChanges = array_values(array_filter(
            $changeSets,
            static fn (array $changeSet): bool => is_string($changeSet['native_url'] ?? null),
        ));

        if (count($nativeChanges) !== 1) {
            return cp_route('secretary.show', $conversation);
        }

        $url = $nativeChanges[0]['native_url'];
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'secretary='.rawurlencode((string) $conversation->id);
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

    private function presentChange($change, $user): array
    {
        if (! $user
            || $change->resource_type !== 'entry'
            || ! in_array($change->status, ['draft', 'published'], true)) {
            return [
                'id' => (string) $change->id,
                'status' => (string) $change->status,
                'summary' => (string) ($change->summary ?: $change->resource_id),
                'native_url' => null,
                'resource_title' => null,
                'public_url' => null,
            ];
        }

        try {
            $entry = Entry::find((string) $change->resource_id);

            if (! $entry || ! $user->can('view', $entry)) {
                throw new \RuntimeException('Entry is not available to this user.');
            }

            return [
                'id' => (string) $change->id,
                'status' => (string) $change->status,
                'summary' => (string) ($change->summary ?: $change->resource_id),
                'native_url' => $entry->editUrl(),
                'resource_title' => trim((string) $entry->get('title')) ?: (string) $change->resource_id,
                'public_url' => $entry->absoluteUrl(),
            ];
        } catch (Throwable) {
            return [
                'id' => (string) $change->id,
                'status' => (string) $change->status,
                'summary' => (string) ($change->summary ?: $change->resource_id),
                'native_url' => null,
                'resource_title' => null,
                'public_url' => null,
            ];
        }
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
