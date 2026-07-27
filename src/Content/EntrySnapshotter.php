<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use DateTimeInterface;
use JsonSerializable;
use Statamic\Contracts\Entries\Entry;

final class EntrySnapshotter
{
    /** @return array<string, mixed> */
    public function snapshot(Entry $entry): array
    {
        $authoringEntry = $this->authoringEntry($entry);

        return [
            'id' => $authoringEntry->id(),
            'collection' => $authoringEntry->collection()->handle(),
            'site' => $authoringEntry->locale(),
            'blueprint' => $authoringEntry->blueprint()->handle(),
            'slug' => $authoringEntry->slug(),
            'parent_id' => $authoringEntry->parent()?->id(),
            'published' => $authoringEntry->published(),
            'has_working_copy' => $entry->revisionsEnabled() && $entry->hasWorkingCopy(),
            'data' => $authoringEntry->data()->all(),
        ];
    }

    public function fingerprint(Entry|array $entry): string
    {
        $snapshot = $entry instanceof Entry ? $this->snapshot($entry) : $entry;

        return hash('sha256', json_encode(
            $this->canonicalize($snapshot),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    public function liveFingerprint(Entry $entry): string
    {
        return $this->fingerprint([
            'id' => $entry->id(),
            'collection' => $entry->collection()->handle(),
            'site' => $entry->locale(),
            'blueprint' => $entry->blueprint()->handle(),
            'slug' => $entry->slug(),
            'parent_id' => $entry->parent()?->id(),
            'published' => $entry->published(),
            'data' => $entry->data()->all(),
        ]);
    }

    public function authoringEntry(Entry $entry): Entry
    {
        if ($entry->revisionsEnabled() && $entry->hasWorkingCopy()) {
            return $entry->fromWorkingCopy();
        }

        return $entry;
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof JsonSerializable) {
            return $this->canonicalize($value->jsonSerialize());
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $this->canonicalize($value->toArray());
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
