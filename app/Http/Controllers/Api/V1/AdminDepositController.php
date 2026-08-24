<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DepositStatus;
use App\Http\Controllers\Concerns\StreamsMediaDownloads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deposit\ApproveDepositRequest;
use App\Http\Requests\Deposit\RejectDepositRequest;
use App\Models\Deposit;
use App\Services\Deposit\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminDepositController extends Controller
{
    use StreamsMediaDownloads;

    public function __construct(private DepositService $depositService) {}

    public function index(Request $request): JsonResponse
    {
        $page     = $request->integer('page', 1);
        $pageSize = $request->integer('page_size', 15);
        $status   = $request->filled('status') ? DepositStatus::tryFrom($request->input('status')) : null;
        $userId   = $request->filled('user_id') ? (string) $request->input('user_id') : null;

        $deposits = $this->depositService->listForAdmin($page, $pageSize, $status, $userId);

        return $this->respond('Deposits retrieved successfully.', [
            'deposits'   => collect($deposits->items())->map(fn(Deposit $d) => $this->depositPayload($d))->all(),
            'pagination' => [
                'current_page' => $deposits->currentPage(),
                'last_page'    => $deposits->lastPage(),
                'per_page'     => $deposits->perPage(),
                'total'        => $deposits->total(),
            ],
        ]);
    }

    public function show(Deposit $deposit): JsonResponse
    {
        return $this->respond('Deposit retrieved successfully.', [
            'deposit' => $this->depositPayload($deposit),
        ]);
    }

    public function approve(ApproveDepositRequest $request, Deposit $deposit): JsonResponse
    {
        $validated     = $request->validated();
        $approvedAmount = array_key_exists('approved_amount', $validated) ? $validated['approved_amount'] : null;
        $adminNote     = $validated['admin_note'] ?? null;

        $updated = $this->depositService->approve(
            $deposit->id,
            $request->user()->id,
            $approvedAmount,
            $adminNote,
        );

        return $this->respond('Deposit approved successfully.', [
            'deposit' => $this->depositPayload($updated),
        ]);
    }

    public function reject(RejectDepositRequest $request, Deposit $deposit): JsonResponse
    {
        $updated = $this->depositService->reject(
            $deposit->id,
            $request->user()->id,
            $request->validated('rejection_reason'),
        );

        return $this->respond('Deposit rejected successfully.', [
            'deposit' => $this->depositPayload($updated),
        ]);
    }

    public function downloadProof(Deposit $deposit): BinaryFileResponse|JsonResponse
    {
        $media = $deposit->getFirstMedia('proof_of_payment');

        if ($media === null) {
            return $this->respond('Proof image not found.', null, 404);
        }

        return $this->downloadMedia($media);
    }

    private function depositPayload(Deposit $deposit): array
    {
        return [
            'id'                    => $deposit->id,
            'user_id'               => $deposit->user_id,
            'user'                  => $deposit->user ? [
                'id'    => $deposit->user->id,
                'name'  => $deposit->user->name,
                'email' => $deposit->user->email,
            ] : null,
            'admin_bank_setting_id' => $deposit->admin_bank_setting_id,
            'currency'              => $deposit->currency?->value,
            'claimed_amount'        => $deposit->claimed_amount,
            'approved_amount'       => $deposit->approved_amount,
            'transfer_note'         => $deposit->transfer_note,
            'status'                => $deposit->status?->value,
            'admin_note'            => $deposit->admin_note,
            'rejection_reason'      => $deposit->rejection_reason,
            'reviewed_by_user_id'   => $deposit->reviewed_by_user_id,
            'reviewed_at'           => $deposit->reviewed_at?->toIso8601String(),
            'proof_of_payment'      => $deposit->proof_image,
            'created_at'            => $deposit->created_at?->toIso8601String(),
            'updated_at'            => $deposit->updated_at?->toIso8601String(),
        ];
    }

    private function respond(string $message, ?array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
        ], $status);
    }
}
