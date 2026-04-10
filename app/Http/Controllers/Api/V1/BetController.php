<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bet\AdminPayoutBetRequest;
use App\Http\Requests\Bet\AdminUpdateBetStatusRequest;
use App\Http\Requests\Bet\StoreBetRequest;
use App\Http\Requests\Bet\UpdateBetRequest;
use App\Models\Bet;
use App\Services\Bet\BetPayoutService;
use App\Services\Bet\BetService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BetController extends Controller
{
    public function __construct(private BetService $betService, private BetPayoutService $betPayoutService) {}

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 10)));

        return $this->respond('Bets retrieved successfully.', [
            'bets' => $this->betService->listForUser($userId, $page, $pageSize),
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 10)));

        return $this->respond('Bets retrieved successfully.', [
            'bets' => $this->betService->listForAdmin($page, $pageSize),
        ]);
    }

    public function acceptedPayments(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 10)));

        return $this->respond('Accepted payment transitions retrieved successfully.', [
            'accepted_payments' => $this->betService->listAcceptedPaymentsForUser($userId, $page, $pageSize),
        ]);
    }

    public function payoutHistory(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 10)));

        return $this->respond('Payout history retrieved successfully.', [
            'payout_history' => $this->betService->listPayoutHistoryForUser($userId, $page, $pageSize),
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

    public function downloadPayoutProof(Request $request, string $bet): BinaryFileResponse|JsonResponse
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

        $media = $resolvedBet->getFirstMedia('payout_proof');

        if ($media === null) {
            return $this->respond('Payout proof not found.', null, 404, [
                'payout_proof_image' => ['No payout proof is attached to this bet.'],
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

    public function payout(AdminPayoutBetRequest $request, string $bet): JsonResponse
    {
        $adminUserId = (int) $request->user()->id;
        /** @var \Illuminate\Http\UploadedFile $payoutProof */
        $payoutProof = $request->file('payout_proof_image');

        try {
            $updatedBet = $this->betPayoutService->payoutWinningBet(
                $bet,
                $adminUserId,
                $payoutProof,
                $request->input('payout_reference'),
                $request->input('payout_note')
            );
        } catch (DomainException $exception) {
            return $this->respond($exception->getMessage(), null, 409, [
                'payout_status' => [$exception->getMessage()],
            ]);
        }

        if ($updatedBet === null) {
            return $this->respond('Bet not found.', null, 404, [
                'bet' => ['The selected bet is invalid.'],
            ]);
        }

        return $this->respond('Bet paid out successfully.', [
            'bet' => $updatedBet,
        ]);
    }

    public function refund(AdminPayoutBetRequest $request, string $bet): JsonResponse
    {
        $adminUserId = (int) $request->user()->id;
        /** @var \Illuminate\Http\UploadedFile $payoutProof */
        $payoutProof = $request->file('payout_proof_image');

        try {
            $updatedBet = $this->betPayoutService->refundBet(
                $bet,
                $adminUserId,
                $payoutProof,
                $request->input('payout_reference'),
                $request->input('payout_note')
            );
        } catch (DomainException $exception) {
            return $this->respond($exception->getMessage(), null, 409, [
                'payout_status' => [$exception->getMessage()],
            ]);
        }

        if ($updatedBet === null) {
            return $this->respond('Bet not found.', null, 404, [
                'bet' => ['The selected bet is invalid.'],
            ]);
        }

        return $this->respond('Bet refunded successfully.', [
            'bet' => $updatedBet,
        ]);
    }

    public function updateReviewStatus(AdminUpdateBetStatusRequest $request, string $bet): JsonResponse
    {
        try {
            $updatedBet = $this->betService->updateReviewStatusForAdmin($bet, (string) $request->validated()['status']);
        } catch (DomainException $exception) {
            return $this->respond($exception->getMessage(), null, 409, [
                'status' => [$exception->getMessage()],
            ]);
        }

        if ($updatedBet === null) {
            return $this->respond('Bet not found.', null, 404, [
                'bet' => ['The selected bet is invalid.'],
            ]);
        }

        return $this->respond('Bet status updated successfully.', [
            'bet' => $updatedBet,
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
