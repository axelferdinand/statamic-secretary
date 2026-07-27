<?php

namespace AxelFerdinand\StatamicSecretary\Commands;

use AxelFerdinand\StatamicSecretary\Exceptions\RelaySecretRotationFailed;
use AxelFerdinand\StatamicSecretary\Relay\RelaySecretRotation;
use Illuminate\Console\Command;

final class RelayRotateSecretCommand extends Command
{
    protected $signature = 'secretary:relay-install-secret-rotation
        {rotation_id : Rotation ID returned by the hosted relay}
        {--grace-minutes=15 : Accept the previous signing secret for 5–60 minutes}';

    protected $description = 'Install a prepared hosted-relay signing secret without exposing it in shell history';

    public function handle(RelaySecretRotation $rotation): int
    {
        $secret = $this->secret('Paste the new relay signing secret');

        if (! is_string($secret) || trim($secret) === '') {
            $this->error('No signing secret was provided.');

            return self::INVALID;
        }

        try {
            $result = $rotation->install(
                $secret,
                (string) $this->argument('rotation_id'),
                (int) $this->option('grace-minutes'),
            );
        } catch (RelaySecretRotationFailed $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $status = $result['duplicate'] ? 'already installed' : 'installed';
        $this->info("Relay signing-secret rotation {$status}.");
        $this->line('Ask the relay operator to promote the same rotation ID.');

        return self::SUCCESS;
    }
}
