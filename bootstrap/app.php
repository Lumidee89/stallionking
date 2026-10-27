<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__ . '/../routes/web.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['role' => EnsureRole::class]);
        $middleware->web(append: [AssignRequestId::class]);
        $middleware->trustProxies(at: '*'); // set to your proxy IPs in production
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['password', 'password_confirmation']);

        // Never leak stack traces or SQL: show a friendly message plus a reference code.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($e instanceof ValidationException || $e instanceof AuthenticationException) {
                return null; // Laravel's defaults are already correct
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $ref = $request->attributes->get('request_id');

            $message = match (true) {
                $status === 403 => 'You do not have permission to do that.',
                $status === 404 => 'We could not find that.',
                $status === 419 => 'Your session expired. Refresh the page and try again.',
                $status === 429 => 'Too many attempts. Wait a moment and try again.',
                $status >= 500 => 'Something went wrong on our side. Your data was not lost; try again, and quote the reference if it continues.',
                default => $e->getMessage() ?: 'Request could not be completed.',
            };

            if (config('app.debug') && $status >= 500) {
                return null; // let Laravel show the debug page locally
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'reference' => $ref], $status);
            }
            return response()->view('errors.generic', compact('message', 'status', 'ref'), $status);
        });
    })
    ->create();
