<?php

use App\Exceptions\BankInfoUpdateTooSoonException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
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
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($e instanceof AuthenticationException && ! $request->expectsJson()) {
                return null;
            }

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

            return response()->json([
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => [
                    'bank_info' => [$e->getMessage()],
                    'next_allowed_at' => [$e->getNextAllowedAt()->toIso8601String()],
                ],
            ], 422);
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
