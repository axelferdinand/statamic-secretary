<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
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
        $fields = $blueprint->fields()->addValues($merged);
        $fields->validate($extraRules);
        $processed = $fields->process()->values()->all();

        if ($storageExisting !== null) {
            return array_replace($storageExisting, array_intersect_key($processed, $patch));
        }

        return array_replace($merged, $processed);
    }
}
