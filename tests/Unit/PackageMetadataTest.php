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
        $description = 'Send an email to your site, and Secretary updates content or builds new pages with your own blueprints and modules — ready for review.';
        $this->assertSame($description, $composer['description']);
        $this->assertSame($description, $composer['extra']['statamic']['description']);
    }

    public function test_marketplace_documentation_is_present_and_describes_page_creation(): void
    {
        $documentation = (string) file_get_contents(dirname(__DIR__, 2).'/DOCUMENTATION.md');

        $this->assertStringContainsString('composer require axelferdinand/statamic-secretary', $documentation);
        $this->assertStringContainsString('create new pages', $documentation);
        $this->assertStringContainsString('Bard or Replicator', $documentation);
    }
}
