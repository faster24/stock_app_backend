<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FirebaseNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    public function __construct(private FirebaseNotificationService $fcmService) {}

    public function sendTest(): JsonResponse
    {
        $user = auth()->user();

        try {
            $success = $this->fcmService->sendToUser(
                $user,
                'Test Notification',
                'This is a test notification from Zarmani108',
                ['type' => 'test', 'timestamp' => (string) now()->timestamp],
                'test'
            );

            return response()->json([
                'message' => $success
                    ? 'Test notification sent successfully'
                    : 'Failed to send — no active tokens',
            ], $success ? 200 : 400);
        } catch (\Throwable $e) {
            Log::error('Test notification error: '.$e->getMessage());

            return response()->json(['message' => 'Error: '.$e->getMessage()], 500);
        }
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:1000',
            'data' => 'nullable|array',
            'notification_type' => 'nullable|string|max:50',
        ]);

        try {
            $targetUser = User::findOrFail($validated['user_id']);

            $success = $this->fcmService->sendToUser(
                $targetUser,
                $validated['title'],
                $validated['body'],
                $validated['data'] ?? [],
                $validated['notification_type'] ?? 'admin'
            );

            return response()->json([
                'message' => $success ? 'Notification sent' : 'Failed to send notification',
            ], $success ? 200 : 400);
        } catch (\Throwable $e) {
            Log::error('Admin notification error: '.$e->getMessage());

            return response()->json(['message' => 'Error: '.$e->getMessage()], 500);
        }
    }

    public function logs(Request $request): JsonResponse
    {
        $query = auth()->user()->notificationLogs();

        if ($request->filled('type')) {
            $query->byType($request->input('type'));
        }

        if ($request->filled('status')) {
            $query->byStatus($request->input('status'));
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->input('end_date'));
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json(['data' => $logs]);
    }

    public function stats(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'data' => [
                'total' => $user->notificationLogs()->count(),
                'sent' => $user->notificationLogs()->sent()->count(),
                'failed' => $user->notificationLogs()->failed()->count(),
                'by_type' => $user->notificationLogs()
                    ->selectRaw('notification_type, count(*) as count')
                    ->groupBy('notification_type')
                    ->get(),
            ],
        ]);
    }
}
