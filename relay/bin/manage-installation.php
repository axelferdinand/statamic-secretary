<?php

use AxelFerdinand\StatamicSecretaryRelay\Bootstrap\RelayFactory;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\InstallationManager;
use AxelFerdinand\StatamicSecretaryRelay\RelayAddress;

require dirname(__DIR__).'/bootstrap.php';

$options = getopt('', ['action:', 'id:', 'sender:']);
$action = is_string($options['action'] ?? null) ? trim($options['action']) : '';
$installationId = is_string($options['id'] ?? null) ? trim($options['id']) : '';
$sender = is_string($options['sender'] ?? null) ? trim($options['sender']) : '';
$actions = [
    'list',
    'status',
    'enable',
    'disable',
    'add-sender',
    'remove-sender',
    'billing-beta',
    'billing-complimentary',
    'billing-required',
];

if (! in_array($action, $actions, true)
    || ($action !== 'list' && $installationId === '')
    || ($action === 'list' && ($installationId !== '' || $sender !== ''))
    || (in_array($action, ['add-sender', 'remove-sender'], true) && $sender === '')
    || (! in_array($action, ['add-sender', 'remove-sender'], true) && $sender !== '')) {
    fwrite(
        STDERR,
        "Usage: php bin/manage-installation.php --action=list OR --action=status|enable|disable|add-sender|remove-sender|billing-beta|billing-complimentary|billing-required --id=si_... [--sender=editor@example.com]\n",
    );
    exit(1);
}

try {
    $store = (new RelayFactory)->store();
    $manager = new InstallationManager($store);
    $sharedAddress = trim((string) getenv('RELAY_SHARED_ADDRESS')) ?: 'secretary@statamic.no';
    $address = new RelayAddress($sharedAddress);

    if ($action === 'list') {
        fwrite(STDOUT, json_encode(
            array_map(
                static fn (Installation $installation): array => publicInstallation($installation, $address),
                $store->installations(),
            ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        )."\n");
        exit(0);
    }

    $installation = match ($action) {
        'status' => $manager->status($installationId),
        'enable' => $manager->setActive($installationId, true),
        'disable' => $manager->setActive($installationId, false),
        'add-sender' => $manager->addSender($installationId, $sender),
        'remove-sender' => $manager->removeSender($installationId, $sender),
        'billing-beta' => $manager->setBillingStatus($installationId, 'beta'),
        'billing-complimentary' => $manager->setBillingStatus($installationId, 'complimentary'),
        'billing-required' => $manager->setBillingStatus($installationId, 'pending'),
    };
    fwrite(STDOUT, json_encode(
        publicInstallation($installation, $address),
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
        'billing' => [
            'status' => $installation->billingStatus,
            'customer_id' => $installation->stripeCustomerId,
            'subscription_id' => $installation->stripeSubscriptionId,
            'period_end' => $installation->billingPeriodEnd === null
                ? null
                : gmdate('c', $installation->billingPeriodEnd),
        ],
        'route_token' => $installation->routeToken,
        'address' => $installation->publicAlias
            ? $address->publicAddress($installation->publicAlias)
            : $address->routeAddress($installation->routeToken),
        'route_address' => $address->routeAddress($installation->routeToken),
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
