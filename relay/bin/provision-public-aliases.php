<?php

use AxelFerdinand\StatamicSecretaryRelay\Bootstrap\RelayFactory;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteSchema;

require dirname(__DIR__).'/bootstrap.php';

try {
    $factory = new RelayFactory;
    $provisioner = $factory->publicAliasProvisioner();

    if (! $provisioner) {
        throw new RuntimeException('Friendly public aliases are not enabled.');
    }

    SqliteSchema::migrate($factory->pdo());
    $installations = array_values(array_filter(
        $factory->store()->installations(),
        static fn ($installation): bool => $installation->active,
    ));

    foreach ($installations as $installation) {
        $provisioner->provision($installation);
    }

    fwrite(STDOUT, json_encode([
        'status' => 'ok',
        'provisioned' => count($installations),
    ], JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Public email alias provisioning failed: {$exception->getMessage()}\n");
    exit(1);
}
