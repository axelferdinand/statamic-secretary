<?php

namespace AxelFerdinand\StatamicSecretary\Http\Middleware;

use AxelFerdinand\StatamicSecretary\Database\SecretaryDatabase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class EnsureSecretaryDatabase
{
    public function __construct(private SecretaryDatabase $database) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->database->ensureReady();
        } catch (Throwable $exception) {
            report($exception);

            $message = 'Secretary could not prepare its private storage. Make sure Laravel\'s storage directory is writable, then try again.';

            if ($request->expectsJson() || $request->is('_secretary/*')) {
                return response()->json(['message' => $message], 503);
            }

            return redirect()->to(cp_route('index'))->withErrors(['secretary_storage' => $message]);
        }

        return $next($request);
    }
}
