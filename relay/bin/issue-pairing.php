<?php

use AxelFerdinand\StatamicSecretaryRelay\Bootstrap\RelayFactory;
use AxelFerdinand\StatamicSecretaryRelay\PairingService;
use AxelFerdinand\StatamicSecretaryRelay\RelayAddress;
use AxelFerdinand\StatamicSecretaryRelay\Security\PublicHttpsUrl;

require dirname(__DIR__).'/bootstrap.php';

$options = getopt('', ['label:', 'sender:', 'minutes::']);
$label = is_string($options['label'] ?? null) ? trim($options['label']) : '';
$senders = $options['sender'] ?? [];
$senders = is_array($senders) ? $senders : [$senders];
$senders = array_values(array_filter(array_map(
    static fn (mixed $sender): string => is_string($sender) ? mb_strtolower(trim($sender)) : '',
    $senders,
)));
$minutes = is_string($options['minutes'] ?? null) ? (int) $options['minutes'] : 30;

if ($label === '' || $senders === []) {
    fwrite(
        STDERR,
        "Usage: php bin/issue-pairing.php --label=\"Site\" --sender=editor@example.com [--minutes=30]\n",
    );
    exit(1);
}

try {
    $factory = new RelayFactory;
    $sharedAddress = trim((string) getenv('RELAY_SHARED_ADDRESS')) ?: 'secretary@statamic.no';
    $service = new PairingService(
        $factory->store(),
        new RelayAddress($sharedAddress),
        new PublicHttpsUrl,
    );
    $issued = $service->issue($label, $senders, $minutes);
    fwrite(STDOUT, json_encode([
        'pairing_code' => $issued->code,
        'expires_at' => gmdate('c', $issued->expiresAt),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Relay pairing code failed: {$exception->getMessage()}\n");
    exit(1);
}
