<?php

namespace Database\Seeders;

use App\Enums\BetResultStatus;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Models\Bet;
use App\Models\BetNumber;
use App\Models\NumberControl;
use App\Models\OddSetting;
use App\Models\TemporaryOddAdjustment;
use App\Models\ThreeDResult;
use App\Models\User;
use App\Services\BettingDistribution\ThreeDDrawScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Fills the 3D break board (GET /admin/betting-distribution/three-d) with data.
 *
 * Everything it writes is tagged with MARKER so a re-run replaces the previous
 * batch instead of stacking on top of it.
 */
class ThreeDDistributionTestingSeeder extends Seeder
{
    private const MARKER = 'ThreeDDistributionTestingSeeder';

    /** number => weight; the rest of the volume is scattered over random numbers. */
    private const HOT_NUMBERS = [
        123 => 12,
        456 => 9,
        7 => 7,
        999 => 6,
        888 => 5,
        250 => 4,
        314 => 3,
        42 => 3,
    ];

    private const ADMIN_ID_FALLBACK = null;

    public function run(): void
    {
        mt_srand(20260813);

        $this->clearPreviousRun();

        $today = Carbon::now('Asia/Bangkok')->startOfDay();
        $drawFloor = $this->ensureDrawFloor($today);
        $bettors = $this->resolveBettors();

        // Belongs to the previous, already-drawn window — must NOT show on the board.
        $this->makeBet($bettors[0], $drawFloor->copy()->subDays(2), Currency::THB, [111 => 40_000]);

        $volumeByNumber = $this->buildVolumeSpread();

        $daysOpen = $drawFloor->diffInDays($today);
        foreach ($volumeByNumber as $number => $amounts) {
            foreach ($amounts as $amount) {
                $bet = $bettors[mt_rand(0, count($bettors) - 1)];
                $placedOn = $drawFloor->copy()->addDays(mt_rand(0, (int) $daysOpen));
                $this->makeBet($bet, $placedOn, Currency::THB, [$number => $amount]);
            }
        }

        // A few multi-number slips, so bet counts are not all 1.
        $this->makeBet($bettors[0], $today, Currency::THB, [123 => 5_000, 456 => 5_000, 789 => 2_000]);
        $this->makeBet($bettors[1 % count($bettors)], $today, Currency::THB, [7 => 3_000, 70 => 1_000, 700 => 1_000]);

        // PENDING still counts toward exposure; REJECTED must not.
        $this->makeBet($bettors[0], $today, Currency::THB, [321 => 8_000], BetStatus::PENDING);
        $this->makeBet($bettors[0], $today, Currency::THB, [322 => 99_000], BetStatus::REJECTED);

        // A smaller MMK book, so the currency toggle shows a different board.
        foreach ([500 => 30_000, 501 => 12_000, 123 => 9_000, 55 => 4_000] as $number => $amount) {
            $this->makeBet($bettors[0], $today, Currency::MMK, [$number => $amount]);
        }

        $this->seedControlsAndOdds();

        $this->command?->info('3D board seeded.');
        $this->command?->info('  draw window : '.$drawFloor->toDateString().' → '.$today->toDateString());
        $this->command?->info('  3D bets     : '.Bet::where('bet_type', BetType::THREE_D->value)->where('payout_note', self::MARKER)->count());
    }

    private function clearPreviousRun(): void
    {
        Bet::query()->where('payout_note', self::MARKER)->delete();

        NumberControl::query()->where('bet_type', '3D')->where('target_opentime', '')->delete();
        TemporaryOddAdjustment::query()->where('bet_type', '3D')->where('target_opentime', '')->delete();
    }

    /**
     * The open draw starts at the latest 3D result date, so one has to exist for
     * the window to be interesting. Only created when the table is empty.
     */
    private function ensureDrawFloor(Carbon $today): Carbon
    {
        $latest = ThreeDResult::query()->orderByDesc('stock_date')->value('stock_date');

        if ($latest !== null) {
            return Carbon::parse($latest)->startOfDay();
        }

        $floor = $today->copy()->subDays(4);

        ThreeDResult::query()->create([
            'stock_date' => $floor->toDateString(),
            'threed' => '482',
        ]);

        return $floor;
    }

    /** @return array<int, User> */
    private function resolveBettors(): array
    {
        $bettors = User::query()
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'admin'))
            ->limit(5)
            ->get()
            ->all();

        if ($bettors === []) {
            $bettors[] = User::query()->create([
                'username' => 'threed_bettor',
                'email' => 'threed-bettor@example.com',
                'password' => Hash::make('password'),
            ]);
        }

        return $bettors;
    }

    /**
     * Hot numbers get several large slips, then a long tail of small ones — the
     * shape the hot list and the volume sort are meant to surface.
     *
     * @return array<int, array<int, int>> number => list of slip amounts
     */
    private function buildVolumeSpread(): array
    {
        $spread = [];

        foreach (self::HOT_NUMBERS as $number => $weight) {
            $slips = [];
            for ($i = 0; $i < $weight; $i++) {
                $slips[] = mt_rand(3, 12) * 5_000;
            }
            $spread[$number] = $slips;
        }

        for ($i = 0; $i < 70; $i++) {
            $number = mt_rand(0, 999);
            if (isset(self::HOT_NUMBERS[$number])) {
                continue;
            }
            $spread[$number][] = mt_rand(1, 8) * 1_000;
        }

        return $spread;
    }

    /**
     * @param  array<int, int>  $numbers  number => amount
     */
    private function makeBet(
        User $user,
        Carbon $stockDate,
        Currency $currency,
        array $numbers,
        BetStatus $status = BetStatus::ACCEPTED,
    ): void {
        $odd = $this->resolveOdd($currency);
        $total = array_sum($numbers);

        $bet = Bet::query()->create([
            'user_id' => $user->id,
            'bet_type' => BetType::THREE_D,
            'currency' => $currency,
            'target_opentime' => null,          // 3D never carries a session slot
            'stock_date' => $stockDate->toDateString(),
            'total_amount' => number_format($total, 2, '.', ''),
            'status' => $status,
            'bet_result_status' => BetResultStatus::OPEN,
            'placed_at' => $stockDate->copy()->addHours(mt_rand(9, 20))->addMinutes(mt_rand(0, 59)),
            'payout_note' => self::MARKER,
        ]);

        foreach ($numbers as $number => $amount) {
            BetNumber::query()->create([
                'bet_id' => $bet->id,
                'number' => $number,
                'amount' => $amount,
                'odd' => $odd,
                'potential_winning' => number_format($amount * (float) $odd, 2, '.', ''),
            ]);
        }
    }

    private function resolveOdd(Currency $currency): string
    {
        $odd = OddSetting::query()
            ->where('bet_type', BetType::THREE_D->value)
            ->where('currency', $currency->value)
            ->where('is_active', true)
            ->value('odd');

        return number_format((float) ($odd ?? 500), 2, '.', '');
    }

    /**
     * 3D controls and temporary odds are anchored to the open draw (not to a
     * calendar day) and carry the '' opentime sentinel.
     */
    private function seedControlsAndOdds(): void
    {
        $anchor = app(ThreeDDrawScope::class)->anchorDate();

        $adminId = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->value('id') ?? self::ADMIN_ID_FALLBACK;

        NumberControl::query()->create([
            'bet_type' => '3D',
            'currency' => 'THB',
            'number' => 999,
            'target_opentime' => '',
            'stock_date' => $anchor,
            'is_closed' => true,
            'sales_limit' => null,
            'created_by' => $adminId,
        ]);

        NumberControl::query()->create([
            'bet_type' => '3D',
            'currency' => 'THB',
            'number' => 123,
            'target_opentime' => '',
            'stock_date' => $anchor,
            'is_closed' => false,
            'sales_limit' => '250000.00',
            'created_by' => $adminId,
        ]);

        TemporaryOddAdjustment::query()->create([
            'bet_type' => '3D',
            'currency' => 'THB',
            'number' => 456,
            'target_opentime' => '',
            'stock_date' => $anchor,
            'base_odd' => $this->resolveOdd(Currency::THB),
            'adjusted_odd' => '300.00',
            'created_by' => $adminId,
        ]);
    }
}
