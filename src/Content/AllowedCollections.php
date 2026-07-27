<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;

final class AllowedCollections
{
    public function allows(string $handle): bool
    {
        $allowed = (array) config('secretary.content.collections', []);

        return $allowed === [] || in_array($handle, $allowed, true);
    }

    public function ensure(string $handle): void
    {
        if (! $this->allows($handle)) {
            throw new ContentOperationDenied("Secretary is not allowed to access the [{$handle}] collection.");
        }
    }
}
