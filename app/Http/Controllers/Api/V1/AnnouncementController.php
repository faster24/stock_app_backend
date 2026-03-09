<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Announcement\StoreAnnouncementRequest;
use App\Http\Requests\Announcement\UpdateAnnouncementRequest;
use App\Models\Announcement;
use App\Services\Announcement\AnnouncementService;
use Illuminate\Http\JsonResponse;

class AnnouncementController extends Controller
{
    public function __construct(private AnnouncementService $announcementService) {}

    public function index(): JsonResponse
    {
        return $this->respond('Announcements retrieved successfully.', [
            'announcements' => $this->announcementService->list(),
        ]);
    }

    public function show(Announcement $announcement): JsonResponse
    {
        return $this->respond('Announcement retrieved successfully.', [
            'announcement' => $this->announcementService->show($announcement),
        ]);
    }

    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $announcement = $this->announcementService->create($request->validated());

        return $this->respond('Announcement created successfully.', [
            'announcement' => $announcement,
        ], 201);
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $updatedAnnouncement = $this->announcementService->update($announcement, $request->validated());

        return $this->respond('Announcement updated successfully.', [
            'announcement' => $updatedAnnouncement,
        ]);
    }

    public function destroy(Announcement $announcement): JsonResponse
    {
        $this->announcementService->delete($announcement);

        return $this->respond('Announcement deleted successfully.', null);
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
