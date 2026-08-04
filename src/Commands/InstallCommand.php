<?php

namespace AxelFerdinand\StatamicSecretary\Commands;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'secretary:install';

    protected $description = 'Prepare Statamic Secretary after Composer installation';

    public function handle(): int
    {
        $result = $this->call('migrate', [
            '--path' => realpath(__DIR__.'/../../database/migrations'),
            '--realpath' => true,
            '--force' => true,
        ]);

        if ($result !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->components->info('Statamic Secretary is ready. Open Secretary in the Control Panel to finish setup.');

        return self::SUCCESS;
    }
}
