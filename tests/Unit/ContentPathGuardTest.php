<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretary\Content\ContentPathGuard;
use AxelFerdinand\StatamicSecretary\Exceptions\ContentBoundaryViolation;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

class ContentPathGuardTest extends TestCase
{
    public function test_it_allows_a_not_yet_created_directory_beneath_content(): void
    {
        app(ContentPathGuard::class)->ensure(config('secretary.content.root').'/collections/pages/new-page.md');

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_a_symlink_that_escapes_the_content_root(): void
    {
        $files = new Filesystem;
        $outside = __DIR__.'/../__fixtures__/outside';
        $link = config('secretary.content.root').'/linked-outside';
        $files->ensureDirectoryExists($outside);
        symlink($outside, $link);

        try {
            $this->expectException(ContentBoundaryViolation::class);
            app(ContentPathGuard::class)->ensure($link.'/entry.md');
        } finally {
            @unlink($link);
            $files->deleteDirectory($outside);
        }
    }

    public function test_it_rejects_a_content_file_symlinked_to_an_outside_file(): void
    {
        $files = new Filesystem;
        $outside = __DIR__.'/../__fixtures__/outside-file.yaml';
        $link = config('secretary.content.root').'/linked-file.yaml';
        $files->put($outside, 'title: Outside');
        symlink($outside, $link);

        try {
            $this->expectException(ContentBoundaryViolation::class);
            app(ContentPathGuard::class)->ensure($link);
        } finally {
            @unlink($link);
            $files->delete($outside);
        }
    }

    public function test_it_rejects_a_hardlinked_content_file(): void
    {
        $files = new Filesystem;
        $outside = __DIR__.'/../__fixtures__/outside-hardlink.yaml';
        $link = config('secretary.content.root').'/hardlinked-file.yaml';
        $files->put($outside, 'title: Outside');
        link($outside, $link);

        try {
            $this->expectException(ContentBoundaryViolation::class);
            app(ContentPathGuard::class)->ensure($link);
        } finally {
            @unlink($link);
            $files->delete($outside);
        }
    }
}
