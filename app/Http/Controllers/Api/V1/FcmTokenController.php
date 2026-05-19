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

        $fcmToken = FcmToken::updateOrCreate(
            ['user_id' => $user->id, 'token' => $validated['token']],
            [
                'device_type' => $validated['device_type'],
                'device_name' => $validated['device_name'] ?? 'Unknown Device',
                'is_active' => true,
                'last_used_at' => now(),
            ]
        );

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
