<?php

use AxelFerdinand\StatamicSecretaryRelay\Bootstrap\RelayFactory;
use AxelFerdinand\StatamicSecretaryRelay\RelayAddress;

require dirname(__DIR__).'/bootstrap.php';

$options = getopt('', ['action:', 'id:', 'rotation-id:', 'transition-minutes::']);
$action = is_string($options['action'] ?? null) ? trim($options['action']) : '';
$installationId = is_string($options['id'] ?? null) ? trim($options['id']) : '';
$rotationId = is_string($options['rotation-id'] ?? null) ? trim($options['rotation-id']) : '';
$transitionMinutes = is_string($options['transition-minutes'] ?? null)
    ? (int) $options['transition-minutes']
    : 15;

if (! in_array($action, ['prepare', 'promote'], true)
    || $installationId === ''
    || ($action === 'prepare' && $rotationId !== '')
    || ($action === 'promote' && $rotationId === '')
    || $transitionMinutes < 5
    || $transitionMinutes > 60) {
    fwrite(
        STDERR,
        "Usage:\n"
        ."  php bin/rotate-installation-route.php --action=prepare --id=si_...\n"
        ."  php bin/rotate-installation-route.php --action=promote --id=si_... --rotation-id=rr_... [--transition-minutes=15]\n",
    );
    exit(1);
}

try {
    $store = (new RelayFactory)->store();

    if ($action === 'prepare') {
        $rotation = $store->prepareRouteRotation($installationId);
        $sharedAddress = trim((string) getenv('RELAY_SHARED_ADDRESS')) ?: 'secretary@statamic.no';
        fwrite(STDOUT, json_encode([
            'installation_id' => $rotation->installationId,
            'rotation_id' => $rotation->rotationId,
            'route_token' => $rotation->routeToken,
            'address' => (new RelayAddress($sharedAddress))->routeAddress($rotation->routeToken),
            'status' => $rotation->duplicate ? 'already_prepared' : 'prepared',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    } else {
        $installation = $store->promoteRouteRotation(
            $installationId,
            $rotationId,
            $transitionMinutes * 60,
        );
        fwrite(STDOUT, json_encode([
            'installation_id' => $installation->id,
            'rotation_id' => $installation->lastRouteRotationId,
            'route_token' => $installation->routeToken,
            'status' => 'promoted',
            'next_rotation_available_at' => $installation->routeRotationAvailableAt === null
                ? null
                : gmdate('c', $installation->routeRotationAvailableAt),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Relay route rotation failed: {$exception->getMessage()}\n");
    exit(1);
}
