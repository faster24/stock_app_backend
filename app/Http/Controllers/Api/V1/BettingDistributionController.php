<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BettingDistribution\AdjustOddsRequest;
use App\Services\BettingDistribution\BettingDistributionService;
use App\Services\BettingDistribution\TemporaryOddAdjustmentService;
use DomainException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BettingDistributionController extends Controller
{
    public function __construct(
        private readonly BettingDistributionService $distributionService,
        private readonly TemporaryOddAdjustmentService $oddAdjustmentService,
    ) {}

    public function getCurrentDistribution(Request $request): JsonResponse
    {
        ['bet_type' => $betType, 'currency' => $currency] = $this->validateBetTypeAndCurrency($request, required: false);

        $data = $this->distributionService->getCurrentPeriodDistribution($betType, $currency);

        return $this->respond('Betting distribution retrieved successfully.', $data);
    }

    public function getDistributionForPeriod(Request $request, string $date, string $targetOpentime): JsonResponse
    {
        $this->validatePeriodParams($date, $targetOpentime);
        ['bet_type' => $betType, 'currency' => $currency] = $this->validateBetTypeAndCurrency($request, required: false);

        $data = $this->distributionService->getDistributionForDate($date, $targetOpentime, $betType, $currency);

        return $this->respond('Betting distribution retrieved successfully.', $data);
    }

    public function adjustOdds(AdjustOddsRequest $request): JsonResponse
    {
        try {
            $result = $this->oddAdjustmentService->adjustOdds(
                $request->validated('stock_date'),
                $request->validated('target_opentime'),
                $request->validated('bet_type'),
                $request->validated('currency'),
                $request->validated('adjustments'),
                (string) $request->user()->id,
            );

            return $this->respond('Odds adjusted successfully.', $result);
        } catch (DomainException $e) {
            return $this->respond($e->getMessage(), null, 409, ['period' => [$e->getMessage()]]);
        }
    }

    public function getPeriodsForToday(): JsonResponse
    {
        $periods = $this->distributionService->getPeriodsForToday();

        return $this->respond("Today's betting periods retrieved successfully.", [
            'date' => now('Asia/Bangkok')->toDateString(),
            'periods' => $periods,
        ]);
    }

    public function getTempOdds(Request $request, string $date, string $targetOpentime): JsonResponse
    {
        $this->validatePeriodParams($date, $targetOpentime);
        ['bet_type' => $betType, 'currency' => $currency] = $this->validateBetTypeAndCurrency($request);

        $odds = $this->oddAdjustmentService->getAppliedOdds($date, $targetOpentime, $betType, $currency);

        return $this->respond('Temporary odds retrieved successfully.', [
            'period' => [
                'target_opentime' => $targetOpentime,
                'stock_date' => $date,
            ],
            'temp_odds' => $odds,
            'total_adjustments' => count($odds),
        ]);
    }

    public function resetOdds(Request $request, string $date, string $targetOpentime): JsonResponse
    {
        $this->validatePeriodParams($date, $targetOpentime);
        ['bet_type' => $betType, 'currency' => $currency] = $this->validateBetTypeAndCurrency($request);

        try {
            $result = $this->oddAdjustmentService->resetOdds($date, $targetOpentime, $betType, $currency);

            return $this->respond('Temporary odds reset successfully.', $result);
        } catch (DomainException $e) {
            return $this->respond($e->getMessage(), null, 409, ['period' => [$e->getMessage()]]);
        }
    }

    private function validatePeriodParams(string $date, string $targetOpentime): void
    {
        $validator = Validator::make(
            ['date' => $date, 'target_opentime' => $targetOpentime],
            [
                'date' => ['required', 'date_format:Y-m-d'],
                'target_opentime' => ['required', Rule::in(['11:00:00', '12:01:00', '15:00:00', '16:30:00'])],
            ],
            [
                'target_opentime.in' => 'The selected target opentime is invalid. Valid times: 11:00:00, 12:01:00, 15:00:00, 16:30:00.',
            ]
        );

        if ($validator->fails()) {
            throw new HttpResponseException(response()->json([
                'message' => 'The given data was invalid.',
                'data' => null,
                'errors' => $validator->errors(),
            ], 422));
        }
    }

    private function validateBetTypeAndCurrency(Request $request, bool $required = true): array
    {
        if (!$required) {
            $betType = $request->query('bet_type', '2D');
            $currency = $request->query('currency', 'THB');

            if (!in_array($betType, ['2D', '3D'], true)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'The given data was invalid.',
                    'data' => null,
                    'errors' => ['bet_type' => ['The selected bet type is invalid.']],
                ], 422));
            }

            if (!in_array($currency, ['MMK', 'THB'], true)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'The given data was invalid.',
                    'data' => null,
                    'errors' => ['currency' => ['The selected currency is invalid.']],
                ], 422));
            }

            return ['bet_type' => $betType, 'currency' => $currency];
        }

        $validator = Validator::make(
            $request->query(),
            [
                'bet_type' => ['required', Rule::in(['2D', '3D'])],
                'currency' => ['required', Rule::in(['MMK', 'THB'])],
            ],
            [
                'bet_type.in' => 'The selected bet type is invalid.',
                'currency.in' => 'The selected currency is invalid.',
            ]
        );

        if ($validator->fails()) {
            throw new HttpResponseException(response()->json([
                'message' => 'The given data was invalid.',
                'data' => null,
                'errors' => $validator->errors(),
            ], 422));
        }

        return $validator->validated();
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
