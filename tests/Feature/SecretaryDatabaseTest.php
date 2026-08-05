<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Feature;

use AxelFerdinand\StatamicSecretary\Database\SecretaryDatabase;
use AxelFerdinand\StatamicSecretary\Models\Conversation;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Statamic\Facades\User;

class SecretaryDatabaseTest extends TestCase
{
    public function test_it_uses_private_sqlite_when_the_sites_database_is_unavailable(): void
    {
        $directory = sys_get_temp_dir().'/statamic-secretary-'.Str::lower(Str::random(12));
        $databasePath = $directory.'/database.sqlite';

        try {
            config()->set('secretary.database.connection', null);
            config()->set('secretary.database.path', $databasePath);
            config()->set('database.default', 'unavailable_site_database');
            config()->set('database.connections.unavailable_site_database', [
                'driver' => 'sqlite',
                'database' => $directory.'/missing/database.sqlite',
                'prefix' => '',
            ]);

            DB::purge('unavailable_site_database');
            DB::purge(SecretaryDatabase::MANAGED_CONNECTION);
            app()->forgetInstance(SecretaryDatabase::class);

            $database = app(SecretaryDatabase::class);
            $database->registerManagedConnection();

            $this->artisan('secretary:install')->assertSuccessful();

            $this->assertSame(SecretaryDatabase::MANAGED_CONNECTION, $database->connectionName());
            $this->assertFileExists($databasePath);
            $this->assertTrue(Schema::connection(SecretaryDatabase::MANAGED_CONNECTION)->hasTable('secretary_conversations'));
            $this->assertSame(0, Conversation::query()->count());
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function test_it_preserves_an_existing_beta_installation_in_the_site_database(): void
    {
        $path = sys_get_temp_dir().'/statamic-secretary-unused-'.Str::lower(Str::random(12)).'.sqlite';
        config()->set('secretary.database.connection', null);
        config()->set('secretary.database.path', $path);
        config()->set('database.default', 'testing');
        app()->forgetInstance(SecretaryDatabase::class);

        $database = app(SecretaryDatabase::class);
        $database->registerManagedConnection();

        $this->assertSame('testing', $database->connectionName());
        $this->assertTrue($database->ready());
        $this->assertFileDoesNotExist($path);
    }

    public function test_the_first_control_panel_request_initializes_private_storage_without_terminal_access(): void
    {
        $directory = sys_get_temp_dir().'/statamic-secretary-'.Str::lower(Str::random(12));
        $databasePath = $directory.'/database.sqlite';

        try {
            config()->set('secretary.database.connection', null);
            config()->set('secretary.database.path', $databasePath);
            config()->set('database.default', 'unavailable_site_database');
            config()->set('database.connections.unavailable_site_database', [
                'driver' => 'sqlite',
                'database' => $directory.'/missing/database.sqlite',
                'prefix' => '',
            ]);

            DB::purge('unavailable_site_database');
            DB::purge(SecretaryDatabase::MANAGED_CONNECTION);
            app()->forgetInstance(SecretaryDatabase::class);
            app(SecretaryDatabase::class)->registerManagedConnection();

            $user = User::make()->id('owner@example.com')->email('owner@example.com')->makeSuper();
            $user->save();

            $this->actingAs($user)
                ->getJson('/cp/secretary/panel/data')
                ->assertOk()
                ->assertJsonPath('conversations', []);

            $this->assertFileExists($databasePath);
            $this->assertTrue(app(SecretaryDatabase::class)->ready());
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function test_a_direct_conversation_url_cannot_query_storage_before_it_is_initialized(): void
    {
        $directory = sys_get_temp_dir().'/statamic-secretary-'.Str::lower(Str::random(12));
        $databasePath = $directory.'/database.sqlite';

        try {
            config()->set('secretary.database.connection', null);
            config()->set('secretary.database.path', $databasePath);
            config()->set('database.default', 'unavailable_site_database');
            config()->set('database.connections.unavailable_site_database', [
                'driver' => 'sqlite',
                'database' => $directory.'/missing/database.sqlite',
                'prefix' => '',
            ]);

            DB::purge('unavailable_site_database');
            DB::purge(SecretaryDatabase::MANAGED_CONNECTION);
            app()->forgetInstance(SecretaryDatabase::class);
            app(SecretaryDatabase::class)->registerManagedConnection();

            $user = User::make()->id('owner@example.com')->email('owner@example.com')->makeSuper();
            $user->save();

            $this->actingAs($user)
                ->getJson('/cp/secretary/'.Str::ulid())
                ->assertNotFound();

            $this->assertFileExists($databasePath);
            $this->assertTrue(app(SecretaryDatabase::class)->ready());
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }
}
