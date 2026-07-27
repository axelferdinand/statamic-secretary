<?php

use AxelFerdinand\StatamicSecretaryRelay\Bootstrap\RelayFactory;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteSchema;

require dirname(__DIR__).'/bootstrap.php';

try {
    $factory = new RelayFactory;
    SqliteSchema::migrate($factory->pdo());
    fwrite(STDOUT, "Relay database is ready.\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Relay migration failed: {$exception->getMessage()}\n");
    exit(1);
}
