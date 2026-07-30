<?php

namespace AxelFerdinand\StatamicSecretary\Contracts;

use AxelFerdinand\StatamicSecretary\Developer\SecretaryToolContext;

/**
 * Extend Secretary with a read-only, application-owned context tool.
 *
 * Content mutations deliberately remain in Secretary's built-in audited
 * change-set workflow. Custom tools should return JSON-serializable data and
 * must not write content, configuration, files, or external state.
 */
interface SecretaryTool
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, array<string, mixed>> */
    public function parameters(): array;

    /** @return array<int, string> */
    public function required(): array;

    /** @return array<string, mixed> */
    public function execute(SecretaryToolContext $context): array;
}
