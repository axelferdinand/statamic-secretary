<?php

namespace AxelFerdinand\StatamicSecretary\Commands;

use AxelFerdinand\StatamicSecretary\Database\SecretaryDatabase;
use Illuminate\Console\Command;
use Throwable;

final class InstallCommand extends Command
{
    protected $signature = 'secretary:install';

    protected $description = 'Prepare Statamic Secretary after Composer installation';

    public function handle(SecretaryDatabase $database): int
    {
        try {
            $database->ensureReady(
                fn (array $arguments): int => $this->call('migrate', $arguments),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Statamic Secretary is ready. Open Secretary in the Control Panel to finish setup.');

        return self::SUCCESS;
    }
}
