<?php

use AxelFerdinand\StatamicSecretaryRelay\Bootstrap\RelayFactory;
use AxelFerdinand\StatamicSecretaryRelay\Data\HttpResult;
use AxelFerdinand\StatamicSecretaryRelay\Observability\SecurityEventReporter;

require dirname(__DIR__).'/bootstrap.php';

/** @return array<string, string> */
function relay_request_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        if (is_array($headers)) {
            return array_filter($headers, 'is_string');
        }
    }

    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_') && is_string($value)) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = $value;
        }
    }

    if (is_string($_SERVER['CONTENT_TYPE'] ?? null)) {
        $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
    }

    return $headers;
}

function relay_json_result(int $status, string $state): HttpResult
{
    return new HttpResult(
        $status,
        json_encode(['accepted' => false, 'status' => $state], JSON_UNESCAPED_SLASHES)
            ?: '{"accepted":false,"status":"temporary_failure"}',
        ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
    );
}

$method = mb_strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$clientIdentity = is_string($_SERVER['REMOTE_ADDR'] ?? null)
    ? $_SERVER['REMOTE_ADDR']
    : 'unknown';

try {
    if ($contentLength > 1048576) {
        $result = relay_json_result(413, 'request_too_large');
    } else {
        $factory = new RelayFactory;
        $application = $factory->application();
        $headers = relay_request_headers();
        $body = (string) file_get_contents('php://input');

        $result = match ([$method, $path]) {
            ['GET', '/health'] => new HttpResult(
                200,
                '{"status":"ok"}',
                ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            ),
            ['POST', '/v1/postmark/inbound'] => $application->postmarkInbound(
                $headers,
                $body,
                $clientIdentity,
            ),
            ['POST', '/v1/replies'] => $application->reply(
                $headers,
                $method,
                $path,
                $body,
                $clientIdentity,
            ),
            ['POST', '/v1/pairings/claim'] => $application->pairing(
                $headers,
                $body,
                $clientIdentity,
            ),
            ['POST', '/v1/pairings/request'] => $application->requestPairingCode(
                $headers,
                $body,
                $clientIdentity,
            ),
            default => relay_json_result(404, 'not_found'),
        };
    }
} catch (Throwable $exception) {
    SecurityEventReporter::reportBootFailure($exception);
    $result = relay_json_result(503, 'temporary_failure');
}

http_response_code($result->status);
header_remove('X-Powered-By');

foreach ([
    ...$result->headers,
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'DENY',
    'Referrer-Policy' => 'no-referrer',
    'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
    'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
] as $name => $value) {
    header($name.': '.$value);
}

echo $result->body;
