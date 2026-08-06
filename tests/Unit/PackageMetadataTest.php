<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PackageMetadataTest extends TestCase
{
    public function test_public_name_is_secretary_while_technical_package_path_stays_stable(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('axelferdinand/statamic-secretary', $composer['name']);
        $this->assertSame('Secretary', $composer['extra']['statamic']['name']);
        $this->assertStringContainsString('Secretary for Statamic', $composer['description']);
    }
}
