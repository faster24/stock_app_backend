<?php

namespace App\Http\Requests\Bet;

use App\Enums\BetType;
use App\Http\Requests\Auth\AuthFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBetRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'pay_slip_image' => ['prohibited'],
            'bet_type' => [
                'sometimes',
                'required',
                'string',
                Rule::in(array_column(BetType::cases(), 'value')),
            ],
            'target_opentime' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['11:00:00', '12:01:00', '15:00:00', '16:30:00']),
            ],
            'transaction_id_last_two_digits' => ['prohibited'],
            'bet_numbers' => ['sometimes', 'required', 'array'],
            'status' => ['prohibited'],
            'bet_result_status' => ['prohibited'],
            'payout_status' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $betNumbers = $this->input('bet_numbers');

            if (! is_array($betNumbers)) {
                return;
            }

            $seenNumbers = [];

            foreach (array_values($betNumbers) as $index => $entry) {
                if (! is_array($entry)) {
                    $validator->errors()->add('bet_numbers.'.$index, 'Each bet number must be an object with number and amount.');
                    continue;
                }

                $number = $this->resolveInteger($entry['number'] ?? null);
                $amount = $this->resolveInteger($entry['amount'] ?? null);

                if ($number === null) {
                    $validator->errors()->add('bet_numbers.'.$index.'.number', 'The bet_numbers.'.$index.'.number field must be an integer.');
                    continue;
                }

                if ($amount === null || $amount < 1) {
                    $validator->errors()->add('bet_numbers.'.$index.'.amount', 'The bet_numbers.'.$index.'.amount field must be at least 1.');
                }

                if (in_array($number, $seenNumbers, true)) {
                    $validator->errors()->add('bet_numbers.'.$index, 'The bet_numbers.'.$index.' field has a duplicate number.');
                } else {
                    $seenNumbers[] = $number;
                }

                if ($this->input('bet_type') === BetType::TWO_D->value && ($number < 1 || $number > 99)) {
                    $validator->errors()->add('bet_numbers.'.$index, 'The bet_numbers.'.$index.' field must be between 1 and 99 when bet type is 2D.');
                }

                if ($this->input('bet_type') === BetType::THREE_D->value && ($number < 1 || $number > 999)) {
                    $validator->errors()->add('bet_numbers.'.$index, 'The bet_numbers.'.$index.' field must be between 1 and 999 when bet type is 3D.');
                }
            }
        });
    }

    private function resolveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
