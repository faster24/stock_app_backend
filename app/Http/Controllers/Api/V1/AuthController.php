<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AuthService $authService)
    {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $payload = $this->authService->register(
            $request->string('username')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        return $this->respond('Registration successful.', $payload, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $payload = $this->authService->login(
                $request->string('email')->toString(),
                $request->string('password')->toString(),
            );
        } catch (AuthorizationException $exception) {
            return $this->respond('Forbidden.', null, 403, [
                'authorization' => [$exception->getMessage()],
            ]);
        } catch (AuthenticationException $exception) {
            return $this->respond($exception->getMessage(), null, 401, [
                'credentials' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $this->respond('Login successful.', $payload);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->respond('Unauthenticated.', null, 401, [
                'auth' => ['Authentication is required.'],
            ]);
        }

        return $this->respond('Authenticated user profile.', [
            'user' => $this->authService->me($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->respond('Unauthenticated.', null, 401, [
                'auth' => ['Authentication is required.'],
            ]);
        }

        $this->authService->logout($user);

        return $this->respond('Logout successful.', null);
    }

    private function respond(string $message, ?array $data, int $status = 200, ?array $errors = null): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
        ], $status);
    }
}
