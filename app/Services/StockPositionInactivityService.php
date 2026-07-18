<?php

namespace App\Services;

use App\Models\StockPositionRestockFlag;
use App\Support\UnitCostKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockPositionInactivityService
{
    public function __construct(
        protected InventoryStockService $stockService,
    ) {}

    /**
     * @return array{scanned: int, aged: int, inactivated: int, cleared: int, skipped: int}
     */
    public function reconcileStaleZeroStockPositions(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $threshold = $asOf->copy()->subYear();

        $counts = [
            'scanned' => 0,
            'aged' => 0,
            'inactivated' => 0,
            'cleared' => 0,
            'skipped' => 0,
        ];

        foreach ($this->stockService->getStockLevelsList() as $row) {
            $counts['scanned']++;

            $itemId = (int) $row->item_id;
            $officeId = (int) $row->office_id;
            $unitCost = (float) ($row->unit_cost ?? 0);
            $stock = (int) $row->stock;
            $flag = StockPositionRestockFlag::findForPosition($itemId, $officeId, $unitCost);

            if ($stock > 0) {
                if ($flag !== null && (
                    $flag->is_inactive_for_restock
                    || $flag->zero_stock_since !== null
                    || $flag->auto_inactive_snoozed_until !== null
                )) {
                    StockPositionRestockFlag::markActive($itemId, $officeId, $unitCost);
                    $counts['cleared']++;
                }

                continue;
            }

            $zeroSince = $this->resolveZeroStockSince($itemId, $officeId, $unitCost, $asOf);

            if ($zeroSince === null) {
                $counts['skipped']++;

                continue;
            }

            $counts['aged']++;
            StockPositionRestockFlag::rememberZeroStockSince($itemId, $officeId, $unitCost, $zeroSince);

            if ($zeroSince->gt($threshold)) {
                continue;
            }

            $before = StockPositionRestockFlag::findForPosition($itemId, $officeId, $unitCost);
            $after = StockPositionRestockFlag::markAutomaticallyInactive(
                $itemId,
                $officeId,
                $unitCost,
                $zeroSince,
            );

            if ($before === null
                || ! $before->is_inactive_for_restock
                || $before->inactive_source !== StockPositionRestockFlag::SOURCE_AUTOMATIC) {
                if ($after->is_inactive_for_restock
                    && $after->inactive_source === StockPositionRestockFlag::SOURCE_AUTOMATIC
                    && ($before === null || ! $before->is_inactive_for_restock || $before->inactive_source !== StockPositionRestockFlag::SOURCE_AUTOMATIC)) {
                    $counts['inactivated']++;
                } else {
                    $counts['skipped']++;
                }
            }
        }

        return $counts;
    }

    public function resolveZeroStockSince(
        int $itemId,
        int $officeId,
        ?float $unitCost,
        ?Carbon $asOf = null,
    ): ?Carbon {
        unset($asOf);

        $costKey = UnitCostKey::normalize($unitCost);
        $events = $this->dailyDeltas($itemId, $officeId, $costKey);
        if ($events->isEmpty()) {
            return null;
        }

        $balance = 0;
        $zeroSince = null;

        foreach ($events as $event) {
            $balance += (int) $event['delta'];
            $date = Carbon::parse((string) $event['date'])->startOfDay();

            if ($balance <= 0) {
                $balance = 0;
                $zeroSince ??= $date;
            } else {
                $zeroSince = null;
            }
        }

        if ($balance > 0) {
            return null;
        }

        return $zeroSince ?? Carbon::parse((string) $events->first()['date'])->startOfDay();
    }

    /**
     * @return Collection<int, array{date: string, delta: int}>
     */
    protected function dailyDeltas(int $itemId, int $officeId, string $costKey): Collection
    {
        $sources = [
            ['table' => 'acquisitions', 'office' => 'office_id', 'cost' => 'unit_cost', 'date' => 'acquisition_date', 'sign' => 1],
            ['table' => 'transfers', 'office' => 'to_office_id', 'cost' => 'unit_cost', 'date' => 'transfer_date', 'sign' => 1],
            ['table' => 'issuances', 'office' => 'office_id', 'cost' => 'unit_cost', 'date' => 'issuance_date', 'sign' => -1],
            ['table' => 'transfers', 'office' => 'from_office_id', 'cost' => 'unit_cost', 'date' => 'transfer_date', 'sign' => -1],
            ['table' => 'disposals', 'office' => 'office_id', 'cost' => 'acquisition_cost', 'date' => 'disposal_date', 'sign' => -1],
        ];

        $rows = collect();

        foreach ($sources as $source) {
            $query = DB::table($source['table'])
                ->select(
                    $source['date'].' as movement_date',
                    $source['cost'].' as movement_cost',
                    'quantity',
                )
                ->where('item_id', $itemId)
                ->where($source['office'], $officeId)
                ->whereNull('deleted_at');

            foreach ($query->get() as $row) {
                $rowCostKey = UnitCostKey::normalize(
                    $row->movement_cost !== null ? (float) $row->movement_cost : null,
                );

                if ($rowCostKey !== $costKey) {
                    continue;
                }

                $rows->push([
                    'date' => substr((string) $row->movement_date, 0, 10),
                    'delta' => (int) $row->quantity * $source['sign'],
                ]);
            }
        }

        return $rows
            ->groupBy('date')
            ->map(fn (Collection $group, string $date): array => [
                'date' => $date,
                'delta' => (int) $group->sum('delta'),
            ])
            ->sortKeys()
            ->values();
    }
}
