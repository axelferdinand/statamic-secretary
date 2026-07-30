<?php

use AxelFerdinand\StatamicSecretaryRelay\Bootstrap\RelayFactory;
use AxelFerdinand\StatamicSecretaryRelay\Observability\SecurityEventReporter;
use AxelFerdinand\StatamicSecretaryRelay\Persistence\SqliteSchema;
use AxelFerdinand\StatamicSecretaryRelay\PostmarkInboundApi;

require dirname(__DIR__).'/bootstrap.php';

$options = getopt('', ['message-id:']);
$messageId = is_string($options['message-id'] ?? null)
    ? trim($options['message-id'])
    : '';

if (preg_match('/^[A-Za-z0-9-]{1,255}$/D', $messageId) !== 1) {
    fwrite(STDERR, "A valid Postmark message ID is required.\n");
    exit(1);
}

try {
    $factory = new RelayFactory;
    SqliteSchema::migrate($factory->pdo());
    $application = $factory->application();
    $payload = (new PostmarkInboundApi(
        trim((string) getenv('RELAY_POSTMARK_SERVER_TOKEN')),
    ))->message($messageId);
    $username = trim((string) getenv('RELAY_POSTMARK_WEBHOOK_USER'));
    $password = trim((string) getenv('RELAY_POSTMARK_WEBHOOK_PASSWORD'));
    $result = $application->postmarkInbound(
        [
            'Authorization' => 'Basic '.base64_encode($username.':'.$password),
            'Content-Type' => 'application/json',
        ],
        json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        'postmark_operator_recovery',
    );
    $response = json_decode($result->body, true);
    $status = is_array($response) && is_string($response['status'] ?? null)
        ? $response['status']
        : 'invalid_response';
    $accepted = $result->status === 200
        && in_array($status, ['forwarded', 'duplicate', 'selection_required'], true);

    echo json_encode([
        'accepted' => $accepted,
        'http_status' => $result->status,
        'status' => $status,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL;

    exit($accepted ? 0 : 1);
} catch (Throwable $exception) {
    SecurityEventReporter::report($exception);
    fwrite(STDERR, "Statamic Secretary message recovery failed.\n");
    exit(1);
}
