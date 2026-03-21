<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BetType;
use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminAnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analyticsService) {}

    public function kpis(Request $request): JsonResponse
    {
        $filters = $this->validateBaseFilters($request);

        return $this->respond('Analytics KPIs retrieved successfully.', [
            'kpis' => $this->analyticsService->kpis($filters),
        ]);
    }

    public function dailyTrends(Request $request): JsonResponse
    {
        $filters = $this->validateWithOptionalBetType($request);

        return $this->respond('Analytics daily trends retrieved successfully.', [
            'daily_trends' => $this->analyticsService->dailyTrends($filters),
        ]);
    }

    public function statusDistribution(Request $request): JsonResponse
    {
        $filters = $this->validateBaseFilters($request);

        return $this->respond('Analytics status distribution retrieved successfully.', [
            'status_distribution' => $this->analyticsService->statusDistribution($filters),
        ]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'admin_user_id' => ['sometimes', 'integer', 'exists:users,id'],
        ]);

        return $this->respond('Analytics payout metrics retrieved successfully.', [
            'payouts' => $this->analyticsService->payouts($filters),
        ]);
    }

    public function topNumbers(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'bet_type' => ['sometimes', Rule::in(array_column(BetType::cases(), 'value'))],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->respond('Analytics top numbers retrieved successfully.', [
            'top_numbers' => $this->analyticsService->topNumbers($filters),
        ]);
    }

    public function settlementRuns(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return $this->respond('Analytics settlement runs retrieved successfully.', [
            'settlement_runs' => $this->analyticsService->settlementRuns($filters),
        ]);
    }

    private function validateBaseFilters(Request $request): array
    {
        return $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'target_opentime' => ['sometimes', Rule::in(['11:00:00', '12:01:00', '15:00:00', '16:30:00'])],
        ]);
    }

    private function validateWithOptionalBetType(Request $request): array
    {
        return $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'target_opentime' => ['sometimes', Rule::in(['11:00:00', '12:01:00', '15:00:00', '16:30:00'])],
            'bet_type' => ['sometimes', Rule::in(array_column(BetType::cases(), 'value'))],
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

