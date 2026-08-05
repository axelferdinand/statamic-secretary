<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

final class BlueprintDescriber
{
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
    private function field(Field $field): array
    {
        $config = $field->config();

        return array_filter([
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
            'sets' => $config['sets'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
