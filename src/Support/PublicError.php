<?php

namespace AxelFerdinand\StatamicSecretary\Support;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentBoundaryViolation;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentConflict;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentOperationDenied;
use AxelFerdinand\StatamicSecretary\Exceptions\OpenAIRequestFailed;
use Throwable;

final class PublicError
{
    public static function message(Throwable $exception, string $fallback): string
    {
        if ($exception instanceof ContentBoundaryViolation
            || $exception instanceof ContentConflict
            || $exception instanceof ContentOperationDenied) {
            return trim($exception->getMessage()) ?: $fallback;
        }

        if ($exception instanceof OpenAIRequestFailed) {
            return $exception->publicMessage;
        }

        return $fallback;
    }
}
