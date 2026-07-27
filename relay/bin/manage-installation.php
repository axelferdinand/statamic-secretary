<?php

use AxelFerdinand\StatamicSecretaryRelay\Bootstrap\RelayFactory;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\InstallationManager;
use AxelFerdinand\StatamicSecretaryRelay\RelayAddress;

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', ['action:', 'id:', 'sender:']);
$action = is_string($options['action'] ?? null) ? trim($options['action']) : '';
$installationId = is_string($options['id'] ?? null) ? trim($options['id']) : '';
$sender = is_string($options['sender'] ?? null) ? trim($options['sender']) : '';
$actions = ['status', 'enable', 'disable', 'add-sender', 'remove-sender'];

if (! in_array($action, $actions, true)
    || $installationId === ''
    || (in_array($action, ['add-sender', 'remove-sender'], true) && $sender === '')
    || (! in_array($action, ['add-sender', 'remove-sender'], true) && $sender !== '')) {
    fwrite(
        STDERR,
        "Usage: php bin/manage-installation.php --action=status|enable|disable|add-sender|remove-sender --id=si_... [--sender=editor@example.com]\n",
    );
    exit(1);
}

try {
    $manager = new InstallationManager((new RelayFactory)->store());
    $installation = match ($action) {
        'status' => $manager->status($installationId),
        'enable' => $manager->setActive($installationId, true),
        'disable' => $manager->setActive($installationId, false),
        'add-sender' => $manager->addSender($installationId, $sender),
        'remove-sender' => $manager->removeSender($installationId, $sender),
    };
    $sharedAddress = trim((string) getenv('RELAY_SHARED_ADDRESS')) ?: 'secretary@statamic.no';
    fwrite(STDOUT, json_encode(
        publicInstallation($installation, new RelayAddress($sharedAddress)),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    )."\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Relay installation management failed: {$exception->getMessage()}\n");
    exit(1);
}

/** @return array<string, mixed> */
function publicInstallation(Installation $installation, RelayAddress $address): array
{
    return [
        'installation_id' => $installation->id,
        'label' => $installation->label,
        'active' => $installation->active,
        'route_token' => $installation->routeToken,
        'address' => $address->routeAddress($installation->routeToken),
        'webhook_url' => $installation->webhookUrl,
        'senders' => $installation->senders,
        'secret_rotation' => [
            'pending_rotation_id' => $installation->pendingRotationId,
            'last_rotation_id' => $installation->lastRotationId,
            'previous_secret_expires_at' => $installation->previousSecretExpiresAt === null
                ? null
                : gmdate('c', $installation->previousSecretExpiresAt),
        ],
        'route_rotation' => [
            'pending_rotation_id' => $installation->pendingRouteRotationId,
            'pending_route_token' => $installation->pendingRouteToken,
            'last_rotation_id' => $installation->lastRouteRotationId,
            'next_rotation_available_at' => $installation->routeRotationAvailableAt === null
                ? null
                : gmdate('c', $installation->routeRotationAvailableAt),
        ],
    ];
}
