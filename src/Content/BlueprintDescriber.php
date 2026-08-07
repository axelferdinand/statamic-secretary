<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use Illuminate\Support\Arr;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

final class BlueprintDescriber
{
    private const MAX_NESTING_DEPTH = 6;

    /** @return array<string, mixed> */
    public function describe(Blueprint $blueprint): array
    {
        return [
            'handle' => $blueprint->handle(),
            'title' => $blueprint->title(),
            'fields' => $blueprint->fields()->all()->map(
                fn (Field $field): array => $this->field($field)
            )->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function describeSet(Blueprint $blueprint, string $fieldHandle, string $setHandle): array
    {
        $field = $blueprint->fields()->all()->get($fieldHandle);

        if (! $field instanceof Field) {
            throw new ContentOperationDenied("Field [{$fieldHandle}] was not found in blueprint [{$blueprint->handle()}].");
        }

        if (! in_array($field->type(), ['bard', 'replicator'], true)) {
            throw new ContentOperationDenied("Field [{$fieldHandle}] is not a Bard or Replicator field.");
        }

        $fieldtype = $field->fieldtype();
        $sets = $fieldtype->flattenedSetsConfig();
        $set = $sets->get($setHandle);

        if (! is_array($set)) {
            throw new ContentOperationDenied("Set [{$setHandle}] was not found in field [{$fieldHandle}].");
        }

        $fields = $fieldtype->fields($setHandle)->all()->map(
            fn (Field $nested): array => $this->field($nested, 1, true)
        )->values()->all();

        return array_filter([
            'blueprint' => $blueprint->handle(),
            'field' => $fieldHandle,
            'field_type' => $field->type(),
            'set' => $setHandle,
            'display' => $set['display'] ?? null,
            'instructions' => $set['instructions'] ?? null,
            'fields' => $fields,
            'value_shape' => $field->type() === 'bard'
                ? [
                    'type' => 'set',
                    'attrs' => [
                        'values' => [
                            'type' => $setHandle,
                            '<field_handle>' => '<value>',
                        ],
                    ],
                ]
                : [
                    'type' => $setHandle,
                    '<field_handle>' => '<value>',
                ],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Return every structured set referenced by the supplied top-level values.
     *
     * @param  array<string, mixed>  $values
     * @return array<int, array{field: string, set: string}>
     */
    public function structuredSetReferences(Blueprint $blueprint, array $values): array
    {
        $references = [];

        foreach ($blueprint->fields()->all() as $field) {
            if (! $field instanceof Field || ! array_key_exists($field->handle(), $values)) {
                continue;
            }

            $value = $values[$field->handle()];

            if (! is_array($value)) {
                continue;
            }

            if ($field->type() === 'bard') {
                foreach ($value as $node) {
                    $set = is_array($node) && ($node['type'] ?? null) === 'set'
                        ? Arr::get($node, 'attrs.values.type')
                        : null;

                    if (is_string($set) && $set !== '') {
                        $references[$field->handle().':'.$set] = [
                            'field' => $field->handle(),
                            'set' => $set,
                        ];
                    }
                }
            }

            if ($field->type() === 'replicator') {
                foreach ($value as $row) {
                    $set = is_array($row) ? ($row['type'] ?? null) : null;

                    if (is_string($set) && $set !== '') {
                        $references[$field->handle().':'.$set] = [
                            'field' => $field->handle(),
                            'set' => $set,
                        ];
                    }
                }
            }
        }

        return array_values($references);
    }

    /** @return array<string, mixed> */
    private function field(Field $field, int $depth = 0, bool $expandStructuredSets = false): array
    {
        $config = $field->config();
        $description = [
            'handle' => $field->handle(),
            'type' => $field->type(),
            'display' => $field->display(),
            'instructions' => $field->instructions(),
            'required' => $field->isRequired(),
            'localizable' => $field->isLocalizable(),
            'editable' => ! in_array($field->visibility(), ['computed', 'read_only'], true),
            'validation' => $config['validate'] ?? null,
            'options' => $config['options'] ?? null,
            'max_items' => $config['max_items'] ?? null,
            'max_files' => $config['max_files'] ?? null,
            'container' => $config['container'] ?? null,
            'folder' => $config['folder'] ?? null,
        ];

        if ($depth < self::MAX_NESTING_DEPTH && in_array($field->type(), ['bard', 'replicator'], true)) {
            $description['sets'] = $expandStructuredSets
                ? $this->expandedSets($field, $depth)
                : $this->setCatalog($field);
        }

        if ($depth < self::MAX_NESTING_DEPTH && $field->type() === 'grid') {
            $description['fields'] = $field->fieldtype()->fields()->all()->map(
                fn (Field $nested): array => $this->field($nested, $depth + 1, true)
            )->values()->all();
        }

        return array_filter($description, static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, array<string, mixed>> */
    private function setCatalog(Field $field): array
    {
        $fieldtype = $field->fieldtype();

        return $fieldtype->flattenedSetsConfig()->map(function (array $set, string $handle) use ($fieldtype): array {
            return array_filter([
                'display' => $set['display'] ?? null,
                'instructions' => $set['instructions'] ?? null,
                'field_handles' => $fieldtype->fields($handle)->all()->keys()->values()->all(),
                'exact_schema_required' => true,
            ], static fn (mixed $value): bool => $value !== null);
        })->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function expandedSets(Field $field, int $depth): array
    {
        $fieldtype = $field->fieldtype();

        return $fieldtype->flattenedSetsConfig()->map(function (array $set, string $handle) use ($fieldtype, $depth): array {
            return array_filter([
                'display' => $set['display'] ?? null,
                'instructions' => $set['instructions'] ?? null,
                'fields' => $fieldtype->fields($handle)->all()->map(
                    fn (Field $nested): array => $this->field($nested, $depth + 1, true)
                )->values()->all(),
            ], static fn (mixed $value): bool => $value !== null);
        })->all();
    }
}
