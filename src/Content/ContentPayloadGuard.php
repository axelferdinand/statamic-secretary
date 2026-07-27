<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;

final class ContentPayloadGuard
{
    /** @param  array<string, mixed>  $payload */
    public function ensure(array $payload, string $label): array
    {
        $maximum = max(1000, (int) config('secretary.limits.max_resource_characters', 250000));
        $serialized = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (mb_strlen($serialized) > $maximum) {
            throw new ContentOperationDenied("{$label} is too large to send to the configured model safely.");
        }

        return $payload;
    }
}
