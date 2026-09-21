<?php

namespace App\Services\Bet;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\DigitControl;
use App\Models\Wallet;
use App\Services\Service;
use Illuminate\Support\Facades\DB;

/**
 * Decides which numbers on a slip are refused because the admin has marked one
 * of their digits hot for this period.
 *
 * A number is covered when EITHER digit is hot (hot 6 covers 61 and 16 alike).
 * Covered numbers are not banned — they are held to a same-amount rule:
 *  - each covered number needs its reverse on the same slip (a double is its
 *    own reverse);
 *  - every covered number on the slip carries one amount, doubles included;
 *  - that amount matches the one the player already uses for covered numbers
 *    in this draw, so the rule cannot be dodged by splitting it across slips.
 *
 * How a number was entered (Direct, R, a bulk pick) is deliberately irrelevant:
 * only amounts decide. `origin` is still accepted on the wire and ignored here.
 *
 * Returns verdicts rather than throwing, so BetService keeps a single throw that
 * reports closed numbers, sales limits and hot digits in one round trip.
 */
class HotFirstDigitGuard extends Service
{
    public const REASON_UNPAIRED = 'reverse_unpaired';

    public const REASON_AMOUNT_MISMATCH = 'amount_mismatch';

    public const ORIGIN_DIRECT = 'direct';

    public const ORIGIN_REVERSE = 'reverse';

    /**
     * `blocked` maps number => REASON_*. `hotDigits` and `requiredAmount` (the
     * amount the draw already holds the player to, null when the slip sets it)
     * are there for the refusal message.
     *
     * @param  list<array{number: int, amount: int, origin?: string}>  $numberEntries
     * @return array{blocked: array<int, string>, hotDigits: list<int>, requiredAmount: int|null}
     */
    public function blockedNumbers(
        array $numberEntries,
        string $betType,
        string $currency,
        ?string $opentime,
        string $stockDate,
        string $userId,
        ?string $excludeBetId = null,
    ): array {
        $none = ['blocked' => [], 'hotDigits' => [], 'requiredAmount' => null];

        // 2D only: a 3D number has no digit rows on any board here.
        if ($betType !== BetType::TWO_D->value) {
            return $none;
        }

        $opentime = (string) $opentime;
        $hotDigits = $this->hotDigits($currency, $opentime, $stockDate);

        if ($hotDigits === []) {
            return $none;
        }

        // Per number, whatever origin each line claimed.
        $slipTotals = [];
        foreach ($numberEntries as $entry) {
            $number = (int) $entry['number'];
            $slipTotals[$number] = ($slipTotals[$number] ?? 0) + (int) $entry['amount'];
        }

        $covered = array_filter(
            $slipTotals,
            fn (int $number): bool => $this->isCovered($number, $hotDigits),
            ARRAY_FILTER_USE_KEY,
        );

        if ($covered === []) {
            return $none;
        }

        $blocked = [];

        foreach (array_keys($covered) as $number) {
            if (! array_key_exists($this->mirror($number), $slipTotals)) {
                $blocked[$number] = self::REASON_UNPAIRED;
            }
        }

        $drawAmounts = $this->drawAmounts($currency, $opentime, $stockDate, $userId, $excludeBetId, $hotDigits);
        // Several amounts already in the draw only happens when bets predate the
        // digit going hot; there is no single amount to hold the player to then,
        // so only the slip's own uniformity is enforced.
        $requiredAmount = count($drawAmounts) === 1 ? $drawAmounts[0] : null;

        $slipAmounts = array_values(array_unique($covered));
        $mismatch = count($slipAmounts) > 1
            || ($requiredAmount !== null && $slipAmounts[0] !== $requiredAmount);

        if ($mismatch) {
            foreach (array_keys($covered) as $number) {
                $blocked[$number] ??= self::REASON_AMOUNT_MISMATCH;
            }
        }

        ksort($blocked);

        return ['blocked' => $blocked, 'hotDigits' => $hotDigits, 'requiredAmount' => $requiredAmount];
    }

    /** The reverse of a 0-99 number: 34 => 43, 5 (i.e. 05) => 50. */
    public function mirror(int $number): int
    {
        return ($number % 10) * 10 + intdiv($number, 10);
    }

    /**
     * @param  list<int>  $hotDigits
     */
    private function isCovered(int $number, array $hotDigits): bool
    {
        return in_array(intdiv($number, 10), $hotDigits, true)
            || in_array($number % 10, $hotDigits, true);
    }

    /**
     * Distinct per-number amounts the player already has on covered numbers in
     * this draw. Summed within each bet, never across bets: two slips of 61/16
     * at 100 are both "100", not a 200.
     *
     * The wallet row lock serializes this player's slips, so two submitted at
     * once cannot each see an empty draw and set different amounts. The bets are
     * read with a locking read on purpose: by now the transaction's snapshot is
     * pinned by the sold-volume SUM, and a plain select would miss a slip this
     * player committed a moment ago.
     *
     * @param  list<int>  $hotDigits
     * @return list<int>
     */
    private function drawAmounts(
        string $currency,
        string $opentime,
        string $stockDate,
        string $userId,
        ?string $excludeBetId,
        array $hotDigits,
    ): array {
        Wallet::query()->where('user_id', $userId)->lockForUpdate()->first();

        $rows = DB::table('bet_numbers')
            ->join('bets', 'bets.id', '=', 'bet_numbers.bet_id')
            ->where('bets.user_id', $userId)
            ->where('bets.bet_type', BetType::TWO_D->value)
            ->where('bets.currency', $currency)
            ->where('bets.target_opentime', $opentime)
            ->whereDate('bets.stock_date', $stockDate)
            ->whereIn('bets.status', [BetStatus::PENDING->value, BetStatus::ACCEPTED->value])
            ->when($excludeBetId !== null, fn ($q) => $q->where('bets.id', '!=', $excludeBetId))
            ->groupBy('bet_numbers.bet_id', 'bet_numbers.number')
            ->select(['bet_numbers.number', DB::raw('SUM(bet_numbers.amount) as total')])
            ->sharedLock()
            ->get();

        $amounts = [];
        foreach ($rows as $row) {
            if ($this->isCovered((int) $row->number, $hotDigits)) {
                $amounts[(int) $row->total] = true;
            }
        }

        return array_keys($amounts);
    }

    /**
     * @return list<int>
     */
    private function hotDigits(string $currency, string $opentime, string $stockDate): array
    {
        return DigitControl::query()
            ->where('bet_type', BetType::TWO_D->value)
            ->where('currency', $currency)
            ->where('target_opentime', $opentime)
            ->where('stock_date', $stockDate)
            ->orderBy('digit')
            ->pluck('digit')
            ->map(intval(...))
            ->all();
    }
}
