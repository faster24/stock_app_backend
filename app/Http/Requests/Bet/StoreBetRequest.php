<?php

namespace App\Http\Requests\Bet;

use App\Enums\BetType;
use App\Http\Requests\Auth\AuthFormRequest;
use Closure;
use Illuminate\Validation\Rule;

class StoreBetRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'bet_type' => [
                'required',
                'string',
                Rule::in(array_column(BetType::cases(), 'value')),
            ],
            'target_opentime' => [
                'required',
                'string',
                Rule::in(['11:00:00', '12:01:00', '15:00:00', '16:30:00']),
            ],
            'amount' => ['required', 'integer', 'min:1'],
            'bet_numbers' => ['required', 'array'],
            'status' => ['prohibited'],
            'bet_result_status' => ['prohibited'],
            'payout_status' => ['prohibited'],
            'bet_numbers.*' => [
                'integer',
                'distinct',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $number = (int) $value;
                    $betType = $this->input('bet_type');

                    if ($betType === BetType::TWO_D->value && ($number < 10 || $number > 99)) {
                        $fail('The '.$attribute.' field must be between 10 and 99 when bet type is 2D.');

                        return;
                    }

                    if ($betType === BetType::THREE_D->value && ($number < 100 || $number > 999)) {
                        $fail('The '.$attribute.' field must be between 100 and 999 when bet type is 3D.');
                    }
                },
            ],
        ];
    }
}
