<?php

namespace AxelFerdinand\StatamicSecretary\Tests;

use AxelFerdinand\StatamicSecretary\ServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $fixtures = __DIR__.'/__fixtures__';

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('statamic.revisions.enabled', true);
        $app['config']->set('statamic.revisions.path', $fixtures.'/revisions');
        $app['config']->set('secretary.content.root', $fixtures.'/content');
    }

    protected function setUp(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(__DIR__.'/__fixtures__/content/collections');
        $files->ensureDirectoryExists(__DIR__.'/__fixtures__/content/globals');
        $files->ensureDirectoryExists(__DIR__.'/__fixtures__/content/navigation');
        $files->ensureDirectoryExists(__DIR__.'/__fixtures__/content/taxonomies');
        $files->ensureDirectoryExists(__DIR__.'/__fixtures__/content/trees/navigation');
        $files->ensureDirectoryExists(__DIR__.'/__fixtures__/users');
        $files->ensureDirectoryExists(__DIR__.'/__fixtures__/revisions');

        parent::setUp();

        config()->set('secretary.openai.api_key', 'test-key');
        $this->artisan('migrate', ['--force' => true])->run();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $files = new Filesystem;
        $files->deleteDirectory(__DIR__.'/__fixtures__/content');
        $files->deleteDirectory(__DIR__.'/__fixtures__/users');
        $files->deleteDirectory(__DIR__.'/__fixtures__/revisions');
    }
}
