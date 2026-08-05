<?php

namespace AxelFerdinand\StatamicSecretary\Database;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Builder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class SecretaryDatabase
{
    public const MANAGED_CONNECTION = 'statamic_secretary';

    private const REQUIRED_TABLES = [
        'secretary_conversations',
        'secretary_messages',
        'secretary_change_sets',
        'secretary_settings',
    ];

    private ?string $resolvedConnection = null;

    private bool $ready = false;

    public function __construct(private readonly Filesystem $files) {}

    public function registerManagedConnection(): void
    {
        $path = $this->databasePath();

        config()->set('database.connections.'.self::MANAGED_CONNECTION, [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'transaction_mode' => 'IMMEDIATE',
        ]);
    }

    public function connectionName(): string
    {
        if ($this->resolvedConnection !== null) {
            return $this->resolvedConnection;
        }

        $configured = trim((string) config('secretary.database.connection'));

        if ($configured !== '') {
            return $this->resolvedConnection = $configured === 'default'
                ? (string) config('database.default')
                : $configured;
        }

        if (is_file($this->databasePath())) {
            return $this->resolvedConnection = self::MANAGED_CONNECTION;
        }

        $default = (string) config('database.default');

        // Preserve pre-managed-storage beta installations. A fresh install no
        // longer inherits the site's database; only a database that already
        // contains Secretary tables is treated as the legacy store.
        if ($default !== '' && $default !== self::MANAGED_CONNECTION) {
            try {
                $schema = Schema::connection($default);

                if (collect(self::REQUIRED_TABLES)->contains(
                    fn (string $table): bool => $schema->hasTable($table),
                )) {
                    return $this->resolvedConnection = $default;
                }
            } catch (Throwable) {
                // A flat-file Statamic site may have an unused or incomplete
                // default database configuration. Secretary must ignore it.
            }
        }

        return $this->resolvedConnection = self::MANAGED_CONNECTION;
    }

    public function schema(): Builder
    {
        return Schema::connection($this->connectionName());
    }

    public function ready(): bool
    {
        if ($this->ready) {
            return true;
        }

        try {
            return $this->ready = $this->hasRequiredSchema();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Create the private store and run only Secretary's migrations.
     *
     * @param  null|callable(array<string, mixed>): int  $migrate
     */
    public function ensureReady(?callable $migrate = null): void
    {
        if ($this->ready) {
            return;
        }

        $this->prepareConnection();

        if ($this->hasRequiredSchema()) {
            $this->ready = true;

            return;
        }

        $lock = $this->migrationLock();

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Secretary could not lock its private storage for setup.');
            }

            if (! $this->hasRequiredSchema()) {
                $arguments = [
                    '--database' => $this->connectionName(),
                    '--path' => realpath(__DIR__.'/../../database/migrations'),
                    '--realpath' => true,
                    '--force' => true,
                ];
                $result = $migrate
                    ? $migrate($arguments)
                    : app(Kernel::class)->call('migrate', $arguments);

                if ($result !== 0) {
                    throw new RuntimeException('Secretary could not initialize its private storage.');
                }
            }

            if (! $this->hasRequiredSchema()) {
                throw new RuntimeException('Secretary storage is missing one or more required tables.');
            }

            $this->ready = true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function transaction(callable $callback): mixed
    {
        return DB::connection($this->connectionName())->transaction($callback);
    }

    private function prepareConnection(): void
    {
        if ($this->connectionName() !== self::MANAGED_CONNECTION) {
            return;
        }

        if (! extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('Secretary needs the PHP PDO SQLite extension for automatic storage.');
        }

        $path = $this->databasePath();
        $directory = dirname($path);
        $this->files->ensureDirectoryExists($directory, 0750, true);

        if (! is_file($path) && ! touch($path)) {
            throw new RuntimeException("Secretary could not create its private database at [{$path}].");
        }

        if (! is_writable($path)) {
            throw new RuntimeException("Secretary's private database is not writable at [{$path}].");
        }

        DB::purge(self::MANAGED_CONNECTION);
    }

    private function databasePath(): string
    {
        $path = trim((string) config(
            'secretary.database.path',
            storage_path('statamic-secretary/database.sqlite'),
        ));

        if ($path === '') {
            return storage_path('statamic-secretary/database.sqlite');
        }

        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) === 1
            ? $path
            : base_path($path);
    }

    /** @return resource */
    private function migrationLock()
    {
        $directory = storage_path('statamic-secretary');
        $this->files->ensureDirectoryExists($directory, 0750, true);
        $lock = fopen($directory.'/migrate.lock', 'c+');

        if ($lock === false) {
            throw new RuntimeException('Secretary could not create its storage setup lock.');
        }

        return $lock;
    }

    private function hasRequiredSchema(): bool
    {
        $schema = $this->schema();

        return collect(self::REQUIRED_TABLES)->every(
            fn (string $table): bool => $schema->hasTable($table),
        )
            && $schema->hasColumn('secretary_change_sets', 'live_base_fingerprint')
            && $schema->hasColumn('secretary_change_sets', 'review')
            && $schema->hasColumn('secretary_messages', 'reply_to_message_id');
    }
}
