<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    public function test_the_package_scoped_installer_is_safe_to_run_more_than_once(): void
    {
        $this->artisan('secretary:install')
            ->expectsOutputToContain('Secretary is ready')
            ->assertSuccessful();

        $this->artisan('secretary:install')
            ->expectsOutputToContain('Secretary is ready')
            ->assertSuccessful();
    }
}
