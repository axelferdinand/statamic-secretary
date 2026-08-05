<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use Illuminate\Support\Arr;
use Statamic\Fields\Blueprint;

final class BlueprintValues
{
    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $patch
     * @param  array<int, string>  $forbidden
     * @param  array<string, mixed>  $extraRules
     * @param  array<string, mixed>|null  $storageExisting
     * @return array<string, mixed>
     */
    public function mergeAndValidate(
        Blueprint $blueprint,
        array $existing,
        array $patch,
        array $forbidden = [],
        array $extraRules = [],
        ?array $storageExisting = null,
    ): array {
        $editable = $blueprint->fields()->all()
            ->filter(fn ($field): bool => ! in_array($field->visibility(), ['computed', 'read_only'], true))
            ->keys()
            ->diff($forbidden)
            ->all();
        $invalid = array_values(array_diff(array_keys($patch), $editable));

        if ($invalid !== []) {
            throw new ContentOperationDenied('Secretary may not edit unknown, identity, or read-only fields: '.implode(', ', $invalid));
        }

        $merged = array_replace($existing, $patch);
        $definitions = $blueprint->fields();
        $present = $definitions->all()->keys()->intersect(array_keys($merged))->values()->all();
        $presentDefinitions = $definitions->only(...$present);
        $preProcessed = $presentDefinitions
            ->addValues(Arr::only($merged, $present))
            ->preProcess()
            ->values()
            ->all();
        $validatable = array_replace($merged, $preProcessed);

        $definitions->addValues($validatable)->validate($extraRules);

        $processed = $presentDefinitions
            ->addValues(Arr::only($validatable, $present))
            ->process()
            ->values()
            ->all();

        if ($storageExisting !== null) {
            $updates = [];

            foreach (array_keys($patch) as $handle) {
                $updates[$handle] = $this->preserveUnchangedStorage(
                    $existing[$handle] ?? null,
                    $merged[$handle] ?? null,
                    $processed[$handle] ?? null,
                );
            }

            return array_replace($storageExisting, $updates);
        }

        return array_replace($merged, $processed);
    }

    private function preserveUnchangedStorage(mixed $before, mixed $input, mixed $processed): mixed
    {
        if ($input === $before) {
            return $before;
        }

        if (! is_array($before) || ! is_array($input) || ! is_array($processed)) {
            return $processed;
        }

        if (array_is_list($before) && array_is_list($input) && array_is_list($processed)) {
            $restored = $processed;
            $matchedBefore = [];

            foreach ($input as $index => $inputItem) {
                if (! array_key_exists($index, $processed)) {
                    continue;
                }

                $matchingIndex = null;

                foreach ($before as $beforeIndex => $beforeItem) {
                    if (! isset($matchedBefore[$beforeIndex]) && $inputItem === $beforeItem) {
                        $matchingIndex = $beforeIndex;
                        break;
                    }
                }

                if ($matchingIndex !== null) {
                    $matchedBefore[$matchingIndex] = true;
                    $restored[$index] = $before[$matchingIndex];

                    continue;
                }

                if (array_key_exists($index, $before)) {
                    $restored[$index] = $this->preserveUnchangedStorage(
                        $before[$index],
                        $inputItem,
                        $processed[$index],
                    );
                }
            }

            return $restored;
        }

        $restored = $processed;

        foreach ($processed as $key => $processedValue) {
            if (array_key_exists($key, $before) && array_key_exists($key, $input)) {
                $restored[$key] = $this->preserveUnchangedStorage(
                    $before[$key],
                    $input[$key],
                    $processedValue,
                );
            }
        }

        return $restored;
    }
}
