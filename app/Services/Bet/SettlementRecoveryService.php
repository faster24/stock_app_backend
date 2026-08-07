<?php

namespace App\Services\Bet;

use App\Exceptions\SettlementRevertRequiredException;
use App\Models\SettlementReversal;
use App\Models\ThreeDResult;
use App\Models\TwoDResult;
use App\Services\Service;
use App\Services\ThreeDResult\ThreeDResultService;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Orchestrates manual result entry and revert-and-resettle corrections.
 */
class SettlementRecoveryService extends Service
{
    public function __construct(
        private SettlementReversalService $reversalService,
        private BetSettlementService $settlementService,
        private ThreeDResultService $threeDResultService,
    ) {}

    /**
     * Manually enter a 2D result (external API failed) and settle it.
     *
     * @param  array{stock_date: string, open_time: string, twod: string}  $attrs
     * @return array{result: TwoDResult, summary: array}
     */
    public function createManualTwoDResult(array $attrs, string $adminUserId): array
    {
        $openTime = $this->normalizeOpenTime($attrs['open_time']);

        $historyId = sprintf(
            '2d-manual-%s-%s',
            $attrs['stock_date'],
            str_replace(':', '', substr($openTime, 0, 5))
        );

        if ($this->settlementService->hasCompletedRun($historyId)) {
            throw new DomainException('This period was already settled manually. Use the correction endpoint instead.');
        }

        $result = TwoDResult::query()->updateOrCreate(
            ['history_id' => $historyId],
            [
                'stock_date' => $attrs['stock_date'],
                'stock_datetime' => $attrs['stock_date'].' '.$openTime,
                'open_time' => $openTime,
                'twod' => $attrs['twod'],
                'payload' => ['source' => 'manual', 'entered_by' => $adminUserId],
            ]
        );

        $summary = $this->settlementService->settleTwoDResult($result);

        return ['result' => $result, 'summary' => $summary];
    }

    /**
     * Correct a 2D result's winning number. When a completed settlement run
     * exists, requires confirmRevert and reverts it before re-settling.
     *
     * @return array{result: TwoDResult, reversal: SettlementReversal|null, summary: array}
     */
    public function correctTwoDResult(
        TwoDResult $result,
        string $newTwod,
        string $adminUserId,
        ?string $reason,
        bool $confirmRevert
    ): array {
        $historyId = (string) $result->history_id;

        if ((string) $result->twod === $newTwod) {
            // Nothing changes — settle only if the period was never settled.
            $summary = $this->settlementService->settleTwoDResult($result);

            return ['result' => $result, 'reversal' => null, 'summary' => $summary];
        }

        $reversal = $this->revertCompletedRunIfAny($historyId, $adminUserId, $reason, $confirmRevert);

        $result->update(['twod' => $newTwod]);

        $summary = $this->settlementService->settleTwoDResult($result->refresh());

        return ['result' => $result, 'reversal' => $reversal, 'summary' => $summary];
    }

    /**
     * Correct a 3D result. Same revert semantics; changing stock_date while a
     * completed run exists is rejected (the settlement window would shift).
     *
     * @return array{result: ThreeDResult, reversal: SettlementReversal|null, summary: array}
     */
    public function correctThreeDResult(
        ThreeDResult $result,
        array $attrs,
        string $adminUserId,
        ?string $reason,
        bool $confirmRevert
    ): array {
        $currentDate = $result->stock_date instanceof Carbon
            ? $result->stock_date->toDateString()
            : (string) $result->stock_date;
        $historyId = BetSettlementService::threeDHistoryId($currentDate);

        $runExists = $this->settlementService->hasCompletedRun($historyId);

        if (
            $runExists
            && array_key_exists('stock_date', $attrs)
            && $attrs['stock_date'] !== $currentDate
        ) {
            throw new DomainException(
                'Cannot change the stock date of a settled 3D result. Revert the settlement first.'
            );
        }

        $changesNumber = array_key_exists('threed', $attrs)
            && (string) $attrs['threed'] !== (string) $result->threed;

        if (! $changesNumber && ! (array_key_exists('stock_date', $attrs) && $attrs['stock_date'] !== $currentDate)) {
            // Nothing settlement-relevant changes — keep the old no-op behavior.
            $updated = $this->threeDResultService->update($result, $attrs);

            return [
                'result' => $updated,
                'reversal' => null,
                'summary' => ['settled' => 0, 'won' => 0, 'lost' => 0, 'skipped' => 0],
            ];
        }

        $reversal = $this->revertCompletedRunIfAny($historyId, $adminUserId, $reason, $confirmRevert);

        $updated = $this->threeDResultService->update($result, $attrs);

        $summary = ($updated->wasChanged() || $reversal !== null)
            ? $this->settlementService->settleThreeDResult($updated->refresh())
            : ['settled' => 0, 'won' => 0, 'lost' => 0, 'skipped' => 0];

        return ['result' => $updated, 'reversal' => $reversal, 'summary' => $summary];
    }

    private function revertCompletedRunIfAny(
        string $historyId,
        string $adminUserId,
        ?string $reason,
        bool $confirmRevert
    ): ?SettlementReversal {
        if (! $this->settlementService->hasCompletedRun($historyId)) {
            return null;
        }

        if (! $confirmRevert) {
            throw new SettlementRevertRequiredException($historyId);
        }

        return $this->reversalService->revert(
            $historyId,
            $adminUserId,
            $reason ?? 'Result correction'
        );
    }

    private function normalizeOpenTime(string $openTime): string
    {
        return strlen($openTime) === 5 ? $openTime.':00' : $openTime;
    }
}
