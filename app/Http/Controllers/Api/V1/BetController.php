<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bet\StoreBetRequest;
use App\Http\Requests\Bet\UpdateBetRequest;
use App\Models\Bet;
use App\Services\Bet\BetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BetController extends Controller
{
    public function __construct(private BetService $betService) {}

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 10)));

        return $this->respond('Bets retrieved successfully.', [
            'bets' => $this->betService->listForUser($userId, $page, $pageSize),
        ]);
    }

    public function show(Request $request, string $bet): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $resolvedBet = $this->betService->showForUser($userId, $bet);

        if ($resolvedBet === null) {
            return $this->respond('Bet not found.', null, 404, [
                'bet' => ['The selected bet is invalid.'],
            ]);
        }

        return $this->respond('Bet retrieved successfully.', [
            'bet' => $resolvedBet,
        ]);
    }

    public function store(StoreBetRequest $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $bet = $this->betService->createForUser($userId, $request->validated());

        return $this->respond('Bet created successfully.', [
            'bet' => $bet,
        ], 201);
    }

    public function update(UpdateBetRequest $request, string $bet): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $updatedBet = $this->betService->updateForUser($userId, $bet, $request->validated());

        if ($updatedBet === null) {
            return $this->respond('Bet not found.', null, 404, [
                'bet' => ['The selected bet is invalid.'],
            ]);
        }

        return $this->respond('Bet updated successfully.', [
            'bet' => $updatedBet,
        ]);
    }

    public function destroy(Request $request, string $bet): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $deleted = $this->betService->deleteForUser($userId, $bet);

        if ($deleted === BetService::DELETE_RESULT_NOT_FOUND) {
            return $this->respond('Bet not found.', null, 404, [
                'bet' => ['The selected bet is invalid.'],
            ]);
        }

        if ($deleted === BetService::DELETE_RESULT_CONFLICT) {
            return $this->respond('Bet cannot be deleted.', null, 409, [
                'bet' => ['This bet has dependent results and cannot be deleted.'],
            ]);
        }

        return $this->respond('Bet deleted successfully.', null);
    }

    public function downloadPaySlip(Request $request, string $bet): BinaryFileResponse|JsonResponse
    {
        $user = $request->user();
        $userId = (int) $user->id;

        $resolvedBet = Bet::query()->with('media')->whereKey($bet)->first();

        if ($resolvedBet === null) {
            return $this->respond('Bet not found.', null, 404, [
                'bet' => ['The selected bet is invalid.'],
            ]);
        }

        if (! $user->hasRole('admin') && (int) $resolvedBet->user_id !== $userId) {
            return $this->respond('Bet not found.', null, 404, [
                'bet' => ['The selected bet is invalid.'],
            ]);
        }

        $media = $resolvedBet->getFirstMedia('pay_slip');

        if ($media === null) {
            return $this->respond('Pay slip image not found.', null, 404, [
                'pay_slip_image' => ['No pay slip image is attached to this bet.'],
            ]);
        }

        return response()->download(
            $media->getPath(),
            $media->file_name,
            array_filter([
                'Content-Type' => $media->mime_type,
            ])
        );
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
