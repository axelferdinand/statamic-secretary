<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;

final class AllowedContentSources
{
    public function allows(string $type, string $handle): bool
    {
        $configured = match ($type) {
            'taxonomy' => config('secretary.content.taxonomies', []),
            'global' => config('secretary.content.global_sets', []),
            'navigation' => config('secretary.content.navigations', []),
            default => null,
        };

        if (! is_array($configured)) {
            return false;
        }

        return $configured === [] || in_array($handle, $configured, true);
    }

    public function ensure(string $type, string $handle): void
    {
        if (! $this->allows($type, $handle)) {
            throw new ContentOperationDenied("Secretary is not allowed to use {$type} [{$handle}].");
        }
    }
}
