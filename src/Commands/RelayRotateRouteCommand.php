<?php

namespace AxelFerdinand\StatamicSecretary\Commands;

use AxelFerdinand\StatamicSecretary\Exceptions\RelayRouteRotationFailed;
use AxelFerdinand\StatamicSecretary\Relay\RelayRouteRotation;
use Illuminate\Console\Command;

final class RelayRotateRouteCommand extends Command
{
    protected $signature = 'secretary:relay-install-route-rotation
        {rotation_id : Rotation ID returned by the hosted relay}
        {route_token : New route token returned by the hosted relay}
        {--transition-minutes=15 : Let the previous route start threads for 5–60 minutes}';

    protected $description = 'Install a prepared hosted-relay route while preserving existing email threads';

    public function handle(RelayRouteRotation $rotation): int
    {
        try {
            $result = $rotation->install(
                (string) $this->argument('route_token'),
                (string) $this->argument('rotation_id'),
                (int) $this->option('transition-minutes'),
            );
        } catch (RelayRouteRotationFailed $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $status = $result['duplicate'] ? 'already installed' : 'installed';
        $this->info("Relay route rotation {$status}.");
        $this->line('Ask the relay operator to promote the same rotation ID.');

        return self::SUCCESS;
    }
}
