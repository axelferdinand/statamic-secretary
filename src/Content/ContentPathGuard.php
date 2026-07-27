<?php

namespace AxelFerdinand\StatamicSecretary\Content;

use AxelFerdinand\StatamicSecretary\Exceptions\ContentBoundaryViolation;

final class ContentPathGuard
{
    public function ensure(string $path): void
    {
        $root = (string) (config('secretary.content.root') ?: base_path('content'));
        $root = $this->canonicalDirectory($root, 'Secretary content root');

        $absolutePath = $this->isAbsolutePath($path) ? $path : base_path($path);
        $parent = $this->canonicalExistingAncestor(dirname($absolutePath));

        if ($parent !== $root && ! str_starts_with($parent, $root.DIRECTORY_SEPARATOR)) {
            throw new ContentBoundaryViolation('Secretary refused a content path outside its configured content root.');
        }

        if (file_exists($absolutePath) || is_link($absolutePath)) {
            $target = realpath($absolutePath);

            if ($target === false || ($target !== $root && ! str_starts_with($target, $root.DIRECTORY_SEPARATOR))) {
                throw new ContentBoundaryViolation('Secretary refused a content file that resolves outside its configured content root.');
            }

            $metadata = @lstat($absolutePath);

            if (is_array($metadata) && is_file($absolutePath) && ($metadata['nlink'] ?? 1) > 1) {
                throw new ContentBoundaryViolation('Secretary refused a multiply-linked content file.');
            }
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function canonicalDirectory(string $path, string $label): string
    {
        $canonical = realpath($path);

        if ($canonical === false || ! is_dir($canonical)) {
            throw new ContentBoundaryViolation("{$label} does not exist or cannot be resolved.");
        }

        return rtrim($canonical, DIRECTORY_SEPARATOR);
    }

    private function canonicalExistingAncestor(string $path): string
    {
        $candidate = $path;

        while (! is_dir($candidate)) {
            $parent = dirname($candidate);

            if ($parent === $candidate) {
                throw new ContentBoundaryViolation('Entry parent directory cannot be resolved.');
            }

            $candidate = $parent;
        }

        return $this->canonicalDirectory($candidate, 'Entry parent directory');
    }
}
