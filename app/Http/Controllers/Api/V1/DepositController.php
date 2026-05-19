<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deposit\StoreDepositRequest;
use App\Models\Deposit;
use App\Services\Deposit\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DepositController extends Controller
{
    public function __construct(private DepositService $depositService) {}

    public function index(Request $request): JsonResponse
    {
        $page     = $request->integer('page', 1);
        $pageSize = $request->integer('page_size', 15);

        $deposits = $this->depositService->listForUser($request->user()->id, $page, $pageSize);

        return $this->respond('Deposits retrieved successfully.', [
            'deposits'   => $deposits->items(),
            'pagination' => [
                'current_page' => $deposits->currentPage(),
                'last_page'    => $deposits->lastPage(),
                'per_page'     => $deposits->perPage(),
                'total'        => $deposits->total(),
            ],
        ]);
    }

    public function store(StoreDepositRequest $request): JsonResponse
    {
        $deposit = $this->depositService->createForUser(
            $request->user()->id,
            $request->validated(),
            $request->file('proof_image'),
        );

        return $this->respond('Deposit submitted successfully.', [
            'deposit' => $this->depositPayload($deposit),
        ], 201);
    }

    public function show(Request $request, string $deposit): JsonResponse
    {
        $found = $this->depositService->showForUser($request->user()->id, $deposit);

        if ($found === null) {
            return $this->respond('Deposit not found.', null, 404);
        }

        return $this->respond('Deposit retrieved successfully.', [
            'deposit' => $this->depositPayload($found),
        ]);
    }

    public function downloadProof(Request $request, string $deposit): BinaryFileResponse|JsonResponse
    {
        $user  = $request->user();
        $found = Deposit::query()->with('media')->whereKey($deposit)->first();

        if ($found === null) {
            return $this->respond('Deposit not found.', null, 404);
        }

        if (! $user->hasRole('admin') && $found->user_id !== (string) $user->id) {
            return $this->respond('Deposit not found.', null, 404);
        }

        $media = $found->getFirstMedia('proof_of_payment');

        if ($media === null) {
            return $this->respond('Proof image not found.', null, 404);
        }

        return response()->download(
            $media->getPath(),
            $media->file_name,
            array_filter(['Content-Type' => $media->mime_type])
        );
    }

    public function cancel(Request $request, string $deposit): JsonResponse
    {
        $cancelled = $this->depositService->cancelForUser($request->user()->id, $deposit);

        return $this->respond('Deposit cancelled successfully.', [
            'deposit' => $this->depositPayload($cancelled),
        ]);
    }

    private function depositPayload(Deposit $deposit): array
    {
        return [
            'id'                    => $deposit->id,
            'user_id'               => $deposit->user_id,
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
            'proof_image'           => $deposit->proof_image,
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
