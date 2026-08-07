<?php

namespace AxelFerdinand\StatamicSecretary\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

final class OpenAIRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $publicMessage,
        public readonly string $healthDetails,
        Throwable $previous,
    ) {
        parent::__construct('OpenAI request failed: '.$reason, previous: $previous);
    }

    public static function from(Throwable $exception): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        if ($exception instanceof ConnectionException) {
            return new self(
                'connection',
                'Secretary could not reach OpenAI. Check the connection and try again.',
                'OpenAI could not be reached. Check outbound HTTPS access, then run the checks again.',
                $exception,
            );
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();
            $error = (array) $exception->response->json('error', []);
            $code = mb_strtolower(trim((string) ($error['code'] ?? $error['type'] ?? '')));
            $message = mb_strtolower(trim((string) ($error['message'] ?? '')));

            if ($status === 429 && (
                in_array($code, ['insufficient_quota', 'billing_hard_limit_reached'], true)
                || str_contains($message, 'no credits')
                || str_contains($message, 'quota')
                || str_contains($message, 'billing')
            )) {
                return new self(
                    'credits',
                    'Secretary is connected to OpenAI, but that account has no available credits. Ask an administrator to add credits, then try again.',
                    'The connected OpenAI account has no available credits. Add credits in OpenAI, then run the checks again.',
                    $exception,
                );
            }

            if ($status === 429) {
                return new self(
                    'rate_limit',
                    'OpenAI is temporarily rate-limiting requests. Wait a moment, then try again.',
                    'OpenAI is temporarily rate-limiting this site. Wait a moment, then run the checks again.',
                    $exception,
                );
            }

            if ($status === 401) {
                return new self(
                    'authentication',
                    'Secretary cannot authenticate with OpenAI. Ask an administrator to reconnect the OpenAI API key.',
                    'OpenAI rejected the API key. Reconnect the key, then run the checks again.',
                    $exception,
                );
            }

            if (in_array($status, [403, 404], true)) {
                return new self(
                    'access',
                    'The connected OpenAI account cannot use Secretary’s selected model. Ask an administrator to check the model and project access.',
                    'OpenAI rejected the selected model or project. Check model and project access, then run the checks again.',
                    $exception,
                );
            }
        }

        return new self(
            'request',
            'OpenAI could not complete this request. Try again; if it keeps happening, ask an administrator to run Secretary’s system checks.',
            'OpenAI did not accept the live test request. Check the configured model and project, then run the checks again.',
            $exception,
        );
    }
}
