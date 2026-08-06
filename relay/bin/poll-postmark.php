<?php

use AxelFerdinand\StatamicSecretaryRelay\Bootstrap\RelayFactory;
use AxelFerdinand\StatamicSecretaryRelay\Observability\SecurityEventReporter;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteSchema;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkInboundApi;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkPoller;

require dirname(__DIR__).'/bootstrap.php';

$verbose = in_array('--verbose', $argv, true);

try {
    $factory = new RelayFactory;
    $store = $factory->store();
    SqliteSchema::migrate($factory->pdo());
    $application = $factory->application();
    $username = trim((string) getenv('RELAY_POSTMARK_WEBHOOK_USER'));
    $password = trim((string) getenv('RELAY_POSTMARK_WEBHOOK_PASSWORD'));
    $poller = new PostmarkPoller(
        new PostmarkInboundApi(trim((string) getenv('RELAY_POSTMARK_SERVER_TOKEN'))),
        $store,
        static function (array $payload) use ($application, $username, $password) {
            return $application->postmarkInbound(
                [
                    'Authorization' => 'Basic '.base64_encode($username.':'.$password),
                    'Content-Type' => 'application/json',
                ],
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'postmark_api_poller',
            );
        },
    );
    $counts = $poller->run();

    if ($verbose) {
        fwrite(STDOUT, json_encode($counts, JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    exit(0);
} catch (Throwable $exception) {
    SecurityEventReporter::report($exception);
    fwrite(STDERR, "Secretary Postmark polling failed.\n");
    exit(1);
}
