<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->is_banned) {
            return $this->forbiddenResponse();
        }

        return $next($request);
    }

    private function forbiddenResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Forbidden.',
            'data' => null,
            'errors' => [
                'authorization' => ['Your account is banned.'],
            ],
        ], 403);
    }
}
