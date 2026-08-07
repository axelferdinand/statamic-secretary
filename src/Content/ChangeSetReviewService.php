<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use AxelFerdinand\StatamicSecretary\Models\ChangeSet;
use Statamic\Contracts\Auth\User;

final class ChangeSetReviewService
{
    public function __construct(
        private readonly EntryChangeService $entries,
        private readonly StagedContentChangeService $staged,
    ) {}

    public function decide(ChangeSet $changeSet, string $target, string $decision, User $user): ChangeSet
    {
        if ($changeSet->status !== 'draft') {
            throw new ContentOperationDenied('Only an active Secretary draft can be reviewed field by field.');
        }

        $review = $this->state($changeSet);
        $targets = collect($this->targets($changeSet))->keyBy('key');

        if (! $targets->has($target)) {
            throw new ContentOperationDenied('The selected review target is not part of this change.');
        }

        if (! in_array($decision, ['pending', 'accepted', 'rejected'], true)) {
            throw new ContentOperationDenied('The review decision is invalid.');
        }

        if ($decision === 'pending') {
            unset($review['decisions'][$target]);
        } else {
            $review['decisions'][$target] = $decision;
        }

        $patch = $this->effectivePatch(
            (array) $review['original_patch'],
            (array) $changeSet->before,
            (array) $review['decisions'],
        );

        // Accepting an untouched field changes review metadata only. Avoid
        // rewriting the Statamic working copy when the effective patch did
        // not change; this keeps rapid review clicks deterministic and cheap.
        if (! $this->same((array) $changeSet->patch, $patch)) {
            $changeSet = $changeSet->resource_type === 'entry'
                ? $this->entries->reviseDraft($changeSet, $patch, $user)
                : $this->staged->reviseDraft($changeSet, $patch, $user);
        }

        $changeSet->update([
            'review' => [
                ...$review,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);

        return $changeSet->fresh();
    }

    /** @return array<string, mixed> */
    public function present(ChangeSet $changeSet): array
    {
        $review = $this->state($changeSet);
        $targets = collect($this->targets($changeSet))->map(function (array $target) use ($review): array {
            return [
                ...$target,
                'decision' => (string) ($review['decisions'][$target['key']] ?? 'pending'),
            ];
        })->values();

        return [
            'available' => $changeSet->status === 'draft' && $targets->isNotEmpty(),
            'targets' => $targets->all(),
            'accepted' => $targets->where('decision', 'accepted')->count(),
            'rejected' => $targets->where('decision', 'rejected')->count(),
            'pending' => $targets->where('decision', 'pending')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function state(ChangeSet $changeSet): array
    {
        $review = (array) $changeSet->review;

        return [
            'original_patch' => (array) ($review['original_patch'] ?? $changeSet->patch ?? []),
            'decisions' => (array) ($review['decisions'] ?? []),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function targets(ChangeSet $changeSet): array
    {
        $review = $this->state($changeSet);
        $beforeData = $this->data($changeSet->before);
        $afterData = $this->dataFromPatch($changeSet, (array) $review['original_patch']);
        $targets = [];

        foreach ((array) $review['original_patch'] as $field => $after) {
            $before = $beforeData[$field] ?? null;

            if ($this->isSetList($after) || $this->isSetList($before)) {
                $count = max(is_array($before) ? count($before) : 0, is_array($after) ? count($after) : 0);

                for ($index = 0; $index < $count; $index++) {
                    $beforeSet = is_array($before) ? ($before[$index] ?? null) : null;
                    $afterSet = is_array($after) ? ($after[$index] ?? null) : null;

                    if ($this->same($beforeSet, $afterSet)) {
                        continue;
                    }

                    $type = is_array($afterSet)
                        ? ($afterSet['type'] ?? $afterSet['id'] ?? null)
                        : (is_array($beforeSet) ? ($beforeSet['type'] ?? $beforeSet['id'] ?? null) : null);
                    $targets[] = [
                        'key' => $field.'.'.$index,
                        'field' => $field,
                        'kind' => 'module',
                        'module_index' => $index,
                        'module_type' => is_string($type) ? $type : null,
                        'before' => $beforeSet,
                        'after' => $afterSet,
                    ];
                }

                continue;
            }

            $targets[] = [
                'key' => $field,
                'field' => $field,
                'kind' => 'field',
                'module_index' => null,
                'module_type' => null,
                'before' => $before,
                'after' => $afterData[$field] ?? $after,
            ];
        }

        return $targets;
    }

    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $before
     * @param  array<string, string>  $decisions
     * @return array<string, mixed>
     */
    private function effectivePatch(array $original, array $before, array $decisions): array
    {
        $patch = $original;
        $beforeData = $this->data($before);

        foreach ($decisions as $target => $decision) {
            if ($decision !== 'rejected') {
                continue;
            }

            if (! str_contains($target, '.')) {
                unset($patch[$target]);

                continue;
            }

            [$field, $index] = explode('.', $target, 2);

            if (! isset($patch[$field]) || ! is_array($patch[$field]) || ! ctype_digit($index)) {
                continue;
            }

            $position = (int) $index;
            $beforeValue = is_array($beforeData[$field] ?? null) ? ($beforeData[$field][$position] ?? null) : null;

            if ($beforeValue === null) {
                unset($patch[$field][$position]);
                $patch[$field] = array_values($patch[$field]);
            } else {
                $patch[$field][$position] = $beforeValue;
                ksort($patch[$field]);
                $patch[$field] = array_values($patch[$field]);
            }
        }

        return $patch;
    }

    /** @return array<string, mixed> */
    private function data(?array $snapshot): array
    {
        if (! is_array($snapshot)) {
            return [];
        }

        if (isset($snapshot['data']) && is_array($snapshot['data'])) {
            return $snapshot['data'];
        }

        return $snapshot;
    }

    /** @param  array<string, mixed>  $patch */
    private function dataFromPatch(ChangeSet $changeSet, array $patch): array
    {
        return [
            ...$this->data($changeSet->before),
            ...$patch,
        ];
    }

    private function isSetList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        return collect($value)->contains(
            fn (mixed $item): bool => is_array($item) && (isset($item['type']) || isset($item['id']))
        );
    }

    private function same(mixed $before, mixed $after): bool
    {
        return json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            === json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
