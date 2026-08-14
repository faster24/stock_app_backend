<?php

namespace App\Http\Requests\Bet;

use App\Enums\BetPayoutStatus;
use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;

class AdminListBetsRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(BetStatus::class)],
            'bet_result_status' => ['sometimes', 'nullable', Rule::enum(BetResultStatus::class)],
            'payout_status' => ['sometimes', 'nullable', Rule::enum(BetPayoutStatus::class)],
            'bet_type' => ['sometimes', 'nullable', Rule::enum(BetType::class)],
            'target_opentime' => ['sometimes', 'nullable', 'string'],
            'user_id' => ['sometimes', 'nullable', 'uuid'],
            'stock_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'stock_date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'stock_date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:stock_date_from'],
            'search' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    /**
     * Filter keys the admin bet list accepts, with blanks dropped so an empty
     * query string param never narrows the result set.
     *
     * @return array<string, string>
     */
    public function filters(): array
    {
        return array_filter(
            $this->only([
                'status',
                'bet_result_status',
                'payout_status',
                'bet_type',
                'stock_date',
                'stock_date_from',
                'stock_date_to',
                'target_opentime',
                'user_id',
                'search',
            ]),
            fn ($value) => $value !== null && $value !== '',
        );
    }
}
