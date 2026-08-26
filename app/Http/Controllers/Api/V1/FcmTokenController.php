<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FcmToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FcmTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|min:100',
            'device_type' => 'required|in:android,ios,web',
            'device_name' => 'nullable|string|max:255',
        ]);

        $user = auth()->user();
        $now = now();

        // Keyed on `token` alone, matching the unique index. An FCM token
        // identifies an app install, not an account, so the same device handed
        // to a second player re-presents the very same token: matching on
        // (user_id, token) missed the existing row and the INSERT that followed
        // hit the index as a 1062. The device belongs to whoever signed in on
        // it last, so the row's owner moves with it -- which also stops the
        // previous account's pushes reaching a phone it no longer holds.
        //
        // upsert() over updateOrCreate() because it compiles to a single
        // INSERT ... ON DUPLICATE KEY UPDATE: the mobile app can fire its forced
        // login registration and its boot-effect registration close enough
        // together for a read-then-write to race and both insert.
        FcmToken::upsert(
            [[
                'user_id' => $user->id,
                'token' => $validated['token'],
                'device_type' => $validated['device_type'],
                'device_name' => $validated['device_name'] ?? 'Unknown Device',
                'is_active' => true,
                'last_used_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['token'],
            ['user_id', 'device_type', 'device_name', 'is_active', 'last_used_at', 'updated_at'],
        );

        $fcmToken = FcmToken::where('token', $validated['token'])->firstOrFail();

        return response()->json([
            'message' => 'FCM token registered successfully',
            'data' => $fcmToken,
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
        ]);

        $deleted = FcmToken::where('user_id', auth()->id())
            ->where('token', $validated['token'])
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Token not found'], 404);
        }

        return response()->json(['message' => 'FCM token removed successfully']);
    }

    public function logoutAll(): JsonResponse
    {
        FcmToken::where('user_id', auth()->id())
            ->update(['is_active' => false]);

        return response()->json(['message' => 'All devices logged out successfully']);
    }

    public function index(): JsonResponse
    {
        $tokens = auth()->user()
            ->fcmTokens()
            ->orderByDesc('created_at')
            ->get()
            ->makeHidden(['token']);

        return response()->json([
            'count' => $tokens->count(),
            'data' => $tokens,
        ]);
    }
}
