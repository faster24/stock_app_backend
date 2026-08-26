<?php

use App\Exceptions\BankInfoUpdateTooSoonException;
use App\Exceptions\TooManySecurityPinAttemptsException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

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

        // nginx runs on this box and proxies to php-fpm over loopback, so without
        // this every request reports 127.0.0.1 and each rate limiter below would
        // put the entire user base in one bucket -- locking everyone out at once
        // instead of the attacker.
        //
        // Scoped to loopback on purpose. `at: '*'` would honour an
        // X-Forwarded-For sent by the client itself, letting a caller mint a
        // fresh address per request and walk straight through the limiter.
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Laravel 11 applies no throttling by default and has no
        // RouteServiceProvider to define one. The `api` limiter lives in
        // AppServiceProvider::configureRateLimiting().
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Expected outcomes, not faults. Laravel already ignores
        // ValidationException, AuthenticationException and HttpException; these
        // are this app's own equivalents, each with a render() below turning it
        // into a deliberate 4xx. Left reportable they would dominate the alert
        // channel with routine business rules — a player mistyping a security
        // PIN is not an incident — and the real 500s would be lost among them.
        $exceptions->dontReport([
            BankInfoUpdateTooSoonException::class,
            TooManySecurityPinAttemptsException::class,
            FileUnacceptableForCollection::class,
            \DomainException::class,
            \Spatie\Permission\Exceptions\UnauthorizedException::class,
            // Being throttled is the feature working. Left reportable, the very
            // attack this defends against would fire one Telegram alert per
            // blocked request and take the alert channel down with it.
            ThrottleRequestsException::class,
        ]);

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

        $exceptions->render(function (FileUnacceptableForCollection $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            // A rejected MIME reaching the media collection means a caller got past
            // the FormRequest. That is the caller's mistake, not a server fault, so
            // it answers 422 — and with our own wording: the library's message names
            // the model class and id, which is not the uploader's business.
            return response()->json([
                'message' => 'The uploaded file type is not supported.',
                'data' => null,
                'errors' => [
                    'file' => ['Only JPEG, PNG and WebP images are accepted.'],
                ],
            ], 422);
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

        // Laravel's default is a bare {"message": "Too Many Attempts."}, which
        // breaks the three-key envelope every client parses. retry_after goes in
        // data, not errors, for the same reason as the cooldown above: clients
        // flatten everything under errors into the one sentence they show.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $message = 'Too many attempts. Please try again later.';

            return response()->json([
                'message' => $message,
                'data' => [
                    'code' => 'RATE_LIMITED',
                    'retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 60),
                ],
                'errors' => [
                    'throttle' => [$message],
                ],
            ], 429)
                // Retry-After and the X-RateLimit-* pair are how clients and
                // proxies know when to come back; rebuilding the body must not
                // drop them.
                ->withHeaders($e->getHeaders());
        });

        // Safety net only. A duplicate key reaching here is a bug in the caller's
        // write path -- the fix belongs there, as it did for FCM token
        // registration -- but a raw 500 tells the client nothing and the default
        // handler leaks the offending SQL whenever APP_DEBUG is on. Deliberately
        // NOT in dontReport: this still has to page us, it just stops being
        // unreadable on the way out.
        $exceptions->render(function (QueryException $exception, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            $driverCode = (string) ($exception->errorInfo[1] ?? '');

            // 1062 = duplicate entry. Every other query fault keeps its 500;
            // dressing them all as 4xx would hide real outages.
            if ($sqlState !== '23000' || $driverCode !== '1062') {
                return null;
            }

            return response()->json([
                'message' => 'This record already exists.',
                'data' => null,
                'errors' => [
                    'duplicate' => ['This record already exists.'],
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
