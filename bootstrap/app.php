<?php

use App\Exceptions\BankInfoUpdateTooSoonException;
use App\Exceptions\TooManySecurityPinAttemptsException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    // Auto-discovery double-registers every listener already wired explicitly via
    // Event::listen() in AppServiceProvider::boot() (each handle() method matches
    // both the discovered class and the explicit registration), causing every
    // event to fire its listener twice. This app only uses explicit registration.
    ->withEvents(discover: false)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => 'Spatie\\Permission\\Middleware\\RoleMiddleware',
            'permission' => 'Spatie\\Permission\\Middleware\\PermissionMiddleware',
            'role_or_permission' => 'Spatie\\Permission\\Middleware\\RoleOrPermissionMiddleware',
            'not_banned' => 'App\\Http\\Middleware\\EnsureUserIsNotBanned',
        ]);

        // API-only backend — there is no web `login` route. Without this, Laravel's
        // default Authenticate middleware tries to redirect non-JSON-expecting
        // unauthenticated requests to route('login'), which doesn't exist, crashing
        // with a 500 instead of a clean 401 on every protected route.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e) {
            // API-only backend — there is no web `login` route to fall back to.
            // Always return JSON, regardless of the request's Accept header,
            // or Laravel's default handler crashes trying to redirect to it.
            return response()->json([
                'message' => 'Unauthenticated.',
                'data' => null,
                'errors' => [
                    'auth' => ['Authentication is required.'],
                ],
            ], 401);
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage() ?: 'The given data was invalid.',
                'data' => null,
                'errors' => $exception->errors(),
            ], $exception->status);
        });

        $exceptions->render(function (BankInfoUpdateTooSoonException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            // next_allowed_at belongs in data, not errors: clients flatten every
            // array under errors into the message they show the user, and a raw
            // ISO timestamp glued onto the sentence is not a message.
            return response()->json([
                'message' => $e->getMessage(),
                'data' => [
                    'code' => 'BANK_INFO_COOLDOWN',
                    'next_allowed_at' => $e->getNextAllowedAt()->toIso8601String(),
                ],
                'errors' => [
                    'bank_info' => [$e->getMessage()],
                ],
            ], 422);
        });

        $exceptions->render(function (TooManySecurityPinAttemptsException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            // retry_after belongs in data for the same reason as the cooldown
            // above: clients flatten everything under errors into one sentence.
            return response()->json([
                'message' => $e->getMessage(),
                'data' => [
                    'code' => 'SECURITY_PIN_THROTTLED',
                    'retry_after' => $e->getRetryAfter(),
                ],
                'errors' => [
                    'security_pin' => [$e->getMessage()],
                ],
            ], 429);
        });

        $exceptions->render(function (\DomainException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => [
                    'domain' => [$e->getMessage()],
                ],
            ], 409);
        });

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (get_class($exception) !== 'Spatie\\Permission\\Exceptions\\UnauthorizedException') {
                return null;
            }

            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => 'Forbidden.',
                'data' => null,
                'errors' => [
                    'authorization' => ['You do not have permission to access this resource.'],
                ],
            ], 403);
        });
    })->create();
