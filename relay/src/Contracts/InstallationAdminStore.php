<?php

namespace AxelFerdinand\StatamicSecretaryRelay\Contracts;

use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\Data\RouteRotation;
use AxelFerdinand\StatamicSecretaryRelay\Data\SecretRotation;

interface InstallationAdminStore
{
    public function installationById(string $id): ?Installation;

    public function saveInstallation(Installation $installation): void;

    public function prepareSecretRotation(string $installationId): SecretRotation;

    public function promoteSecretRotation(
        string $installationId,
        string $rotationId,
        int $graceSeconds,
    ): Installation;

    public function prepareRouteRotation(string $installationId): RouteRotation;

    public function promoteRouteRotation(
        string $installationId,
        string $rotationId,
        int $transitionSeconds,
    ): Installation;
}
