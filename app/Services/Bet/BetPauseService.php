<?php

namespace App\Services\Bet;

use App\Exceptions\BettingPausedException;
use App\Models\BetPause;
use App\Services\Service;
use Illuminate\Support\Carbon;

class BetPauseService extends Service
{
    public const DEFAULT_PAUSE_MESSAGE = 'Betting is currently paused.';

    public function list(): array
    {
        return BetPause::query()
            ->orderBy('bet_type')
            ->get()
            ->map(fn (BetPause $pause): array => $this->present($pause))
            ->all();
    }

    public function setPause(array $attributes, ?string $adminId): array
    {
        $isEnabled = (bool) ($attributes['is_enabled'] ?? false);

        $pause = BetPause::query()->updateOrCreate(
            [
                'bet_type' => (string) ($attributes['bet_type'] ?? ''),
            ],
            [
                'is_enabled' => $isEnabled,
                // Convert to the app timezone before assigning: Eloquent's datetime cast
                // stores the wall-clock digits and drops the offset (e.g. "+06:30").
                'pause_from' => $isEnabled
                    ? Carbon::parse($attributes['pause_from'])->setTimezone(config('app.timezone'))
                    : null,
                'message' => $isEnabled ? ($attributes['message'] ?? null) : null,
                'created_by' => $isEnabled ? $adminId : null,
            ],
        );

        return $this->present($pause);
    }

    public function assertBettingNotPaused(string $betType): void
    {
        $pause = BetPause::query()
            ->where('bet_type', $betType)
            ->where('is_enabled', true)
            ->where('pause_from', '<=', Carbon::now())
            ->first();

        if ($pause === null) {
            return;
        }

        // A dedicated exception rather than a bare ValidationException: the
        // client has to tell "betting is off right now" apart from "you typed
        // something wrong", and it could not — every rejection arrived as an
        // anonymous 422 under some field key. The renderer gives this one a
        // `data.code` so a paused bet type reads as a state, not a typo.
        throw new BettingPausedException($betType, $pause->message ?? self::DEFAULT_PAUSE_MESSAGE);
    }

    private function present(BetPause $pause): array
    {
        $status = 'inactive';
        if ($pause->is_enabled && $pause->pause_from !== null) {
            $status = $pause->pause_from->lessThanOrEqualTo(Carbon::now()) ? 'paused' : 'scheduled';
        }

        return [
            'id' => $pause->id,
            'bet_type' => $pause->bet_type->value,
            'is_enabled' => (bool) $pause->is_enabled,
            'pause_from' => $pause->pause_from?->toIso8601String(),
            'status' => $status,
            'message' => $pause->message,
            'updated_at' => $pause->updated_at?->toIso8601String(),
        ];
    }
}
