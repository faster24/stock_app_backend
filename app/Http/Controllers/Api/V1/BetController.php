<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BettingPausedException;
use App\Exceptions\TooManySecurityPinAttemptsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bet\AdminListBetsRequest;
use App\Http\Requests\Bet\AdminUpdateBetStatusRequest;
use App\Http\Requests\Bet\ApproveBetPayoutRequest;
use App\Http\Requests\Bet\BulkApproveBetPayoutRequest;
use App\Http\Requests\Bet\StoreBetRequest;
use App\Http\Requests\Bet\UpdateBetRequest;
use App\Services\Bet\BetPayoutService;
use App\Services\Bet\BetService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class BetController extends Controller
{
    public function __construct(
        private BetService $betService,
        private BetPayoutService $betPayoutService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $userId = (string) $request->user()->id;
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 10)));

        Log::info('Bet list requested.', ['user_id' => $userId, 'page' => $page, 'page_size' => $pageSize]);

        return $this->respond('Bets retrieved successfully.', [
            'bets' => $this->betService->listForUser($userId, $page, $pageSize),
        ]);
    }

    public function adminIndex(AdminListBetsRequest $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 10)));
        $filters = $request->filters();

        Log::info('Admin bet list requested.', ['admin_user_id' => (string) $request->user()->id, 'page' => $page, 'page_size' => $pageSize, 'filters' => $filters]);

        $bets = $this->betService->listForAdmin($page, $pageSize, $filters);

        return $this->respond('Bets retrieved successfully.', [
            'bets' => $bets->items(),
            'pagination' => [
                'current_page' => $bets->currentPage(),
                'last_page' => $bets->lastPage(),
                'per_page' => $bets->perPage(),
                'total' => $bets->total(),
            ],
        ]);
    }

    public function acceptedPayments(Request $request): JsonResponse
    {
        $userId = (string) $request->user()->id;
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 10)));

        Log::info('Accepted payments requested.', ['user_id' => $userId, 'page' => $page, 'page_size' => $pageSize]);

        return $this->respond('Accepted payment transitions retrieved successfully.', [
            'accepted_payments' => $this->betService->listAcceptedPaymentsForUser($userId, $page, $pageSize),
        ]);
    }

    public function payoutHistory(Request $request): JsonResponse
    {
        $userId = (string) $request->user()->id;
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 10)));

        Log::info('Payout history requested.', ['user_id' => $userId, 'page' => $page, 'page_size' => $pageSize]);

        return $this->respond('Payout history retrieved successfully.', [
            'payout_history' => $this->betService->listPayoutHistoryForUser($userId, $page, $pageSize),
        ]);
    }

    public function show(Request $request, string $bet): JsonResponse
    {
        $userId = (string) $request->user()->id;

        Log::info('Bet show requested.', ['user_id' => $userId, 'bet_id' => $bet]);

        $resolvedBet = $this->betService->showForUser($userId, $bet);

        if ($resolvedBet === null) {
            Log::warning('Bet not found on show.', ['user_id' => $userId, 'bet_id' => $bet]);

            return $this->respond('Bet not found.', null, 404, [
                'bet' => ['The selected bet is invalid.'],
            ]);
        }

        return $this->respond('Bet retrieved successfully.', [
            'bet' => $resolvedBet,
        ]);
    }

    public function adminShow(Request $request, string $bet): JsonResponse
    {
        $adminUserId = (string) $request->user()->id;

        Log::info('Admin bet show requested.', ['admin_user_id' => $adminUserId, 'bet_id' => $bet]);

        $resolvedBet = $this->betService->showForAdmin($bet);

        if ($resolvedBet === null) {
            Log::warning('Bet not found on admin show.', ['admin_user_id' => $adminUserId, 'bet_id' => $bet]);

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
        $userId = (string) $request->user()->id;

        Log::info('Bet store requested.', ['user_id' => $userId]);

        try {
            $bet = $this->betService->createForUser($userId, $request->validated());
        } catch (Throwable $e) {
            $this->rethrowLogged($e, 'Bet creation rejected.', 'Unexpected error creating bet.', ['user_id' => $userId]);
        }

        Log::info('Bet created successfully.', ['user_id' => $userId, 'bet_id' => $bet->id]);

        return $this->respond('Bet created successfully.', [
            'bet' => $bet,
        ], 201);
    }

    public function update(UpdateBetRequest $request, string $bet): JsonResponse
    {
        $userId = (string) $request->user()->id;

        Log::info('Bet update requested.', ['user_id' => $userId, 'bet_id' => $bet]);

        try {
            $updatedBet = $this->betService->updateForUser($userId, $bet, $request->validated());
        } catch (Throwable $e) {
            $this->rethrowLogged($e, 'Bet update rejected.', 'Unexpected error updating bet.', ['user_id' => $userId, 'bet_id' => $bet]);
        }

        if ($updatedBet === null) {
            Log::warning('Bet not found on update.', ['user_id' => $userId, 'bet_id' => $bet]);

            return $this->respond('Bet not found.', null, 404, [
                'bet' => ['The selected bet is invalid.'],
            ]);
        }

        Log::info('Bet updated successfully.', ['user_id' => $userId, 'bet_id' => $bet]);

        return $this->respond('Bet updated successfully.', [
            'bet' => $updatedBet,
        ]);
    }

    public function destroy(Request $request, string $bet): JsonResponse
    {
        $userId = (string) $request->user()->id;

        Log::info('Bet delete requested.', ['user_id' => $userId, 'bet_id' => $bet]);

        try {
            $deleted = $this->betService->deleteForUser($userId, $bet);
        } catch (Throwable $e) {
            $this->rethrowLogged($e, 'Bet delete rejected.', 'Unexpected error deleting bet.', ['user_id' => $userId, 'bet_id' => $bet]);
        }

        if ($deleted === BetService::DELETE_RESULT_NOT_FOUND) {
            Log::warning('Bet not found on delete.', ['user_id' => $userId, 'bet_id' => $bet]);

            return $this->respond('Bet not found.', null, 404, [
                'bet' => ['The selected bet is invalid.'],
            ]);
        }

        if ($deleted === BetService::DELETE_RESULT_CONFLICT) {
            Log::warning('Bet delete conflict — has dependent results.', ['user_id' => $userId, 'bet_id' => $bet]);

            return $this->respond('Bet cannot be deleted.', null, 409, [
                'bet' => ['This bet has dependent results and cannot be deleted.'],
            ]);
        }

        Log::info('Bet deleted successfully.', ['user_id' => $userId, 'bet_id' => $bet]);

        return $this->respond('Bet deleted successfully.', null);
    }

    public function updateReviewStatus(AdminUpdateBetStatusRequest $request, string $bet): JsonResponse
    {
        $adminUserId = (string) $request->user()->id;
        $targetStatus = (string) $request->validated()['status'];

        Log::info('Bet review status update requested.', ['admin_user_id' => $adminUserId, 'bet_id' => $bet, 'target_status' => $targetStatus]);

        try {
            $updatedBet = $this->betService->updateReviewStatusForAdmin($bet, $targetStatus, $adminUserId);
        } catch (DomainException $exception) {
            Log::warning('Bet review status update rejected.', ['admin_user_id' => $adminUserId, 'bet_id' => $bet, 'target_status' => $targetStatus, 'reason' => $exception->getMessage()]);

            return $this->respond($exception->getMessage(), null, 409, [
                'status' => [$exception->getMessage()],
            ]);
        } catch (Throwable $e) {
            $this->rethrowLogged($e, 'Bet review status update rejected.', 'Unexpected error updating bet review status.', ['admin_user_id' => $adminUserId, 'bet_id' => $bet, 'target_status' => $targetStatus]);
        }

        if ($updatedBet === null) {
            Log::warning('Bet not found on review status update.', ['admin_user_id' => $adminUserId, 'bet_id' => $bet]);

            return $this->respond('Bet not found.', null, 404, [
                'bet' => ['The selected bet is invalid.'],
            ]);
        }

        Log::info('Bet review status updated successfully.', ['admin_user_id' => $adminUserId, 'bet_id' => $bet, 'status' => $targetStatus]);

        return $this->respond('Bet status updated successfully.', [
            'bet' => $updatedBet,
        ]);
    }

    public function approvePayout(ApproveBetPayoutRequest $request, string $bet): JsonResponse
    {
        $adminUserId = (string) $request->user()->id;
        $validated = $request->validated();

        $model = $this->betService->showForAdmin($bet);

        if ($model === null) {
            return $this->respond('Bet not found.', null, 404, [
                'bet' => ['The selected bet is invalid.'],
            ]);
        }

        Log::info('Bet payout approval requested.', ['admin_user_id' => $adminUserId, 'bet_id' => $bet]);

        try {
            $paidBet = $this->betPayoutService->approve(
                $model,
                $adminUserId,
                $validated['payout_reference'] ?? null,
                $validated['payout_note'] ?? null,
            );
        } catch (DomainException $exception) {
            Log::warning('Bet payout approval rejected.', ['admin_user_id' => $adminUserId, 'bet_id' => $bet, 'reason' => $exception->getMessage()]);

            return $this->respond($exception->getMessage(), null, 409, [
                'payout' => [$exception->getMessage()],
            ]);
        }

        Log::info('Bet payout approved.', ['admin_user_id' => $adminUserId, 'bet_id' => $bet]);

        return $this->respond('Bet payout approved successfully.', [
            'bet' => $paidBet,
        ]);
    }

    public function approvePayoutBulk(BulkApproveBetPayoutRequest $request): JsonResponse
    {
        $adminUserId = (string) $request->user()->id;
        $validated = $request->validated();

        Log::info('Bulk bet payout approval requested.', [
            'admin_user_id' => $adminUserId,
            'stock_date' => $validated['stock_date'],
            'target_opentime' => $validated['target_opentime'],
        ]);

        $summary = $this->betPayoutService->approveBulk(
            (string) $validated['stock_date'],
            (string) $validated['target_opentime'],
            $adminUserId,
            $validated['note'] ?? null,
        );

        Log::info('Bulk bet payout approval completed.', ['admin_user_id' => $adminUserId, 'summary' => $summary]);

        return $this->respond('Bet payouts approved.', [
            'summary' => $summary,
        ]);
    }

    /**
     * Log a failed bet operation at the level it deserves, then re-throw it for
     * the global renderers in bootstrap/app.php to turn into a response.
     *
     * A mistyped security PIN, a paused bet type and an insufficient balance are
     * outcomes, not faults. They were all landing in a `catch (Throwable)` that
     * called Log::error, so the log read "Unexpected error creating bet." for
     * things the app decided on purpose — and once the Telegram channel started
     * carrying errors, each rejection paged someone and throttled the genuine
     * 500s queued behind it. bootstrap/app.php's dontReport() list already
     * exempts these, but dontReport says nothing about an explicit Log::error.
     *
     * @param  array<string, mixed>  $context
     */
    private function rethrowLogged(Throwable $e, string $rejectedMessage, string $unexpectedMessage, array $context): never
    {
        if ($e instanceof DomainException || $e instanceof BettingPausedException || $e instanceof TooManySecurityPinAttemptsException) {
            Log::info($rejectedMessage, $context + ['reason' => $e->getMessage()]);

            throw $e;
        }

        if ($e instanceof ValidationException) {
            // The field keys, not the message: ValidationException::getMessage()
            // is only ever the first error, which hides the rest.
            Log::info($rejectedMessage, $context + ['fields' => array_keys($e->errors())]);

            throw $e;
        }

        Log::error($unexpectedMessage, $context + ['error' => $e->getMessage()]);

        throw $e;
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
