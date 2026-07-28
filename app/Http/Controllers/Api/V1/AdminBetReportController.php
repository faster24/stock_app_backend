<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BetType;
use App\Http\Controllers\Controller;
use App\Services\Report\BetReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminBetReportController extends Controller
{
    private const MAX_RANGE_DAYS_DAILY = 400;

    private const MAX_RANGE_YEARS = 5;

    public function __construct(private readonly BetReportService $betReportService) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);

        return $this->respond('Bet report retrieved successfully.', [
            'report' => $this->betReportService->report($filters),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validateFilters($request);

        $rows = $this->betReportService->csvRows($filters);
        $header = $this->betReportService->csvHeader();

        $filename = sprintf(
            'bet-report-%s-%s-to-%s.csv',
            $filters['granularity'],
            $filters['from'],
            $filters['to']
        );

        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function validateFilters(Request $request): array
    {
        $filters = $this->validateOrFail($request, [
            'granularity' => ['required', Rule::in(['daily', 'monthly', 'yearly'])],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'bet_type' => ['sometimes', Rule::in(array_column(BetType::cases(), 'value'))],
        ]);

        $from = CarbonImmutable::createFromFormat('Y-m-d', $filters['from'])->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $filters['to'])->startOfDay();

        if ($from->diffInYears($to) >= self::MAX_RANGE_YEARS) {
            $this->failRange('The selected range must be shorter than '.self::MAX_RANGE_YEARS.' years.');
        }

        if ($filters['granularity'] === 'daily' && $from->diffInDays($to) > self::MAX_RANGE_DAYS_DAILY) {
            $this->failRange('Daily reports are limited to '.self::MAX_RANGE_DAYS_DAILY.' days.');
        }

        return $filters;
    }

    private function failRange(string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'data' => null,
            'errors' => ['range' => [$message]],
        ], 422));
    }

    private function validateOrFail(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);

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
