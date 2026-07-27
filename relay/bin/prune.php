<?php

use AxelFerdinand\StatamicSecretaryRelay\Bootstrap\RelayFactory;

require dirname(__DIR__).'/bootstrap.php';

$retentionDays = trim((string) getenv('RELAY_RETENTION_DAYS'));
$retentionDays = $retentionDays === '' ? 30 : (int) $retentionDays;

if ($retentionDays < 1 || $retentionDays > 365) {
    fwrite(STDERR, "RELAY_RETENTION_DAYS must be between 1 and 365.\n");
    exit(1);
}

try {
    $counts = (new RelayFactory)->store()->prune(time() - ($retentionDays * 86400));
    fwrite(STDOUT, json_encode($counts, JSON_UNESCAPED_SLASHES)."\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Relay pruning failed: {$exception->getMessage()}\n");
    exit(1);
}
