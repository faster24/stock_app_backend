<?php

namespace App\Services\Bet;

use App\Enums\BetType;
use App\Models\DigitControl;
use App\Services\Service;

/**
 * Decides which numbers on a slip are refused because the admin has closed their
 * first digit for this period.
 *
 * Two carve-outs survive a hot digit:
 *  - a double (Zatu) — 00, 11, … 99 — is always bettable;
 *  - a reverse (R) bet is bettable, but only when the mirror leg actually rides
 *    along on the same slip funded at the same amount. The `origin` flag alone is
 *    client-supplied and therefore forgeable; the paired, paid-for mirror is not,
 *    and buying both legs is exactly the exposure the house accepts for R.
 *
 * Returns verdicts rather than throwing, so BetService keeps a single throw that
 * reports closed numbers, sales limits and hot digits in one round trip.
 */
class HotFirstDigitGuard extends Service
{
    public const REASON_HOT = 'hot_first_digit';

    public const REASON_UNPAIRED = 'reverse_unpaired';

    public const ORIGIN_DIRECT = 'direct';

    public const ORIGIN_REVERSE = 'reverse';

    /**
     * @param  list<array{number: int, amount: int, origin?: string}>  $numberEntries
     * @return array<int, string> number => one of the REASON_* constants
     */
    public function blockedNumbers(
        array $numberEntries,
        string $betType,
        string $currency,
        ?string $opentime,
        string $stockDate,
    ): array {
        // 2D only: a 3D number has no first-digit row on any board here.
        if ($betType !== BetType::TWO_D->value) {
            return [];
        }

        $hotDigits = $this->hotDigits($currency, (string) $opentime, $stockDate);

        if ($hotDigits === []) {
            return [];
        }

        // Kept in separate buckets on purpose: merging them would let a valid R
        // pair launder a direct stake sitting on the same number.
        $directTotals = [];
        $reverseTotals = [];

        foreach ($numberEntries as $entry) {
            $number = (int) $entry['number'];
            $amount = (int) $entry['amount'];

            if (($entry['origin'] ?? self::ORIGIN_DIRECT) === self::ORIGIN_REVERSE) {
                $reverseTotals[$number] = ($reverseTotals[$number] ?? 0) + $amount;

                continue;
            }

            $directTotals[$number] = ($directTotals[$number] ?? 0) + $amount;
        }

        $blocked = [];

        foreach (array_keys($directTotals + $reverseTotals) as $number) {
            if (! in_array(intdiv($number, 10), $hotDigits, true)) {
                continue;
            }

            // A double is its own mirror, so it needs no pairing to be exempt.
            if ($number % 11 === 0) {
                continue;
            }

            if (($directTotals[$number] ?? 0) > 0) {
                $blocked[$number] = self::REASON_HOT;

                continue;
            }

            $staked = $reverseTotals[$number] ?? 0;
            $mirrorStaked = $reverseTotals[$this->mirror($number)] ?? 0;

            if ($staked > 0 && $staked === $mirrorStaked) {
                continue;
            }

            $blocked[$number] = self::REASON_UNPAIRED;
        }

        ksort($blocked);

        return $blocked;
    }

    /** The reverse of a 0-99 number: 34 => 43, 5 (i.e. 05) => 50. */
    public function mirror(int $number): int
    {
        return ($number % 10) * 10 + intdiv($number, 10);
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
            ->pluck('digit')
            ->map(intval(...))
            ->all();
    }
}
