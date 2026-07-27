<?php

use AxelFerdinand\StatamicSecretaryRelay\Bootstrap\RelayFactory;

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', ['action:', 'id:', 'rotation-id:', 'grace-minutes::']);
$action = is_string($options['action'] ?? null) ? trim($options['action']) : '';
$installationId = is_string($options['id'] ?? null) ? trim($options['id']) : '';
$rotationId = is_string($options['rotation-id'] ?? null) ? trim($options['rotation-id']) : '';
$graceMinutes = is_string($options['grace-minutes'] ?? null)
    ? (int) $options['grace-minutes']
    : 15;

if (! in_array($action, ['prepare', 'promote'], true)
    || $installationId === ''
    || ($action === 'prepare' && $rotationId !== '')
    || ($action === 'promote' && $rotationId === '')
    || $graceMinutes < 5
    || $graceMinutes > 60) {
    fwrite(
        STDERR,
        "Usage:\n"
        ."  php bin/rotate-installation-secret.php --action=prepare --id=si_...\n"
        ."  php bin/rotate-installation-secret.php --action=promote --id=si_... --rotation-id=sr_... [--grace-minutes=15]\n",
    );
    exit(1);
}

try {
    $store = (new RelayFactory)->store();

    if ($action === 'prepare') {
        $rotation = $store->prepareSecretRotation($installationId);
        fwrite(STDOUT, json_encode([
            'installation_id' => $rotation->installationId,
            'rotation_id' => $rotation->rotationId,
            'signing_secret' => rtrim(strtr(base64_encode($rotation->signingSecret), '+/', '-_'), '='),
            'status' => $rotation->duplicate ? 'already_prepared' : 'prepared',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    } else {
        $installation = $store->promoteSecretRotation(
            $installationId,
            $rotationId,
            $graceMinutes * 60,
        );
        fwrite(STDOUT, json_encode([
            'installation_id' => $installation->id,
            'rotation_id' => $installation->lastRotationId,
            'status' => 'promoted',
            'previous_secret_expires_at' => $installation->previousSecretExpiresAt === null
                ? null
                : gmdate('c', $installation->previousSecretExpiresAt),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Relay signing-secret rotation failed: {$exception->getMessage()}\n");
    exit(1);
}
