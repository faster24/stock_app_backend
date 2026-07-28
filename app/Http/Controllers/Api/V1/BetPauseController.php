<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BetPause\UpdateBetPauseRequest;
use App\Services\Bet\BetPauseService;
use Illuminate\Http\JsonResponse;

class BetPauseController extends Controller
{
    public function __construct(private readonly BetPauseService $betPauseService) {}

    public function index(): JsonResponse
    {
        return $this->respond('Bet pauses retrieved successfully.', [
            'bet_pauses' => $this->betPauseService->list(),
        ]);
    }

    public function update(UpdateBetPauseRequest $request): JsonResponse
    {
        $betPause = $this->betPauseService->setPause(
            $request->validated(),
            $request->user()?->id,
        );

        return $this->respond('Bet pause updated successfully.', [
            'bet_pause' => $betPause,
        ]);
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
