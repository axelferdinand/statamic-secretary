<?php

use AxelFerdinand\StatamicSecretaryRelay\Bootstrap\RelayFactory;
use AxelFerdinand\StatamicSecretaryRelay\Data\Installation;
use AxelFerdinand\StatamicSecretaryRelay\RelayAddress;
use AxelFerdinand\StatamicSecretaryRelay\Tokens;

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', ['webhook:', 'label:', 'sender:']);
$webhook = is_string($options['webhook'] ?? null) ? trim($options['webhook']) : '';
$label = is_string($options['label'] ?? null) ? trim($options['label']) : '';
$senders = $options['sender'] ?? [];
$senders = is_array($senders) ? $senders : [$senders];
$senders = array_values(array_filter(array_map(
    static fn (mixed $sender): string => is_string($sender) ? mb_strtolower(trim($sender)) : '',
    $senders,
)));

if ($webhook === '' || $label === '' || $senders === []) {
    fwrite(
        STDERR,
        "Usage: php bin/provision.php --webhook=https://site.example/_secretary/webhooks/relay/inbound --label=\"Site\" --sender=editor@example.com\n",
    );
    exit(1);
}

try {
    $factory = new RelayFactory;
    $routeToken = Tokens::route();
    $signingSecret = Tokens::signingSecret();
    $installation = new Installation(
        Tokens::installation(),
        $routeToken,
        $webhook,
        $signingSecret,
        $senders,
        true,
        $label,
    );
    $factory->store()->saveInstallation($installation);
    $sharedAddress = trim((string) getenv('RELAY_SHARED_ADDRESS')) ?: 'secretary@statamic.no';
    $alias = (new RelayAddress($sharedAddress))->routeAddress($routeToken);
    $encodedSecret = rtrim(strtr(base64_encode($signingSecret), '+/', '-_'), '=');
    fwrite(STDOUT, json_encode([
        'installation_id' => $installation->id,
        'route_token' => $routeToken,
        'signing_secret' => $encodedSecret,
        'address' => $alias,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Relay provisioning failed: {$exception->getMessage()}\n");
    exit(1);
}
