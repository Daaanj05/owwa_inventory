<?php

namespace App\Console\Commands;

use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Services\InventoryStockService;
use App\Support\SupplyOfficeResolver;
use App\Support\UnitCostKey;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BackfillIssuedFromOffice extends Command
{
    protected $signature = 'inventory:backfill-issued-from-office {--dry-run : Report only, roll back all changes}';

    protected $description = 'Deduct older requisition issuances from regional supply, re-costed oldest lot first';

    public function handle(InventoryStockService $stock, SupplyOfficeResolver $supplyOfficeResolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $supplyOfficeId = $supplyOfficeResolver->resolve();

        if ($supplyOfficeId === null) {
            $this->error('Regional supply office is not configured.');

            return self::FAILURE;
        }

        /** @var Collection<int, Issuance> $legacy */
        $legacy = Issuance::query()
            ->whereNotNull('requisition_id')
            ->whereNull('issued_from_office_id')
            ->orderBy('issuance_date')
            ->orderBy('id')
            ->get();

        if ($legacy->isEmpty()) {
            $this->info('No older requisition issuances need a regional supply office.');

            return self::SUCCESS;
        }

        $itemIds = $legacy->pluck('item_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $before = $this->stockSnapshot($stock, $itemIds, $supplyOfficeId);
        $splitRows = 0;
        $shortfalls = [];
        $after = [];

        DB::beginTransaction();

        try {
            Issuance::query()->whereKey($legacy->modelKeys())->toBase()->update([
                'issued_from_office_id' => $supplyOfficeId,
                'deleted_at' => now(),
            ]);
            $stock->forgetMovementTotalsCache();

            foreach ($legacy as $issuance) {
                $parts = $this->allocate($stock, $issuance, $supplyOfficeId);
                $allocated = array_sum(array_column($parts, 'quantity'));

                if ($allocated < (int) $issuance->quantity) {
                    $shortfall = (int) $issuance->quantity - $allocated;
                    $shortfalls[(int) $issuance->item_id] = ($shortfalls[(int) $issuance->item_id] ?? 0) + $shortfall;
                    $parts[] = [
                        'unit_cost' => $issuance->unit_cost !== null ? (float) $issuance->unit_cost : null,
                        'quantity' => $shortfall,
                        'unit_ids' => [],
                    ];
                }

                $splitRows += $this->writeParts($issuance, $parts, $supplyOfficeId);
                $stock->forgetMovementTotalsCache();
            }

            $after = $this->stockSnapshot($stock, $itemIds, $supplyOfficeId);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        } finally {
            $stock->forgetMovementTotalsCache();
        }

        $names = Item::query()->whereKey($itemIds)->pluck('name', 'id');

        $this->table(
            ['Item', 'Regional stock before', 'Regional stock after', 'In-stock units at supply', 'Not covered by supply'],
            collect($itemIds)
                ->map(fn (int $itemId): array => [
                    $names[$itemId] ?? "Item #{$itemId}",
                    $before[$itemId]['stock'],
                    $after[$itemId]['stock'],
                    $after[$itemId]['units'] ?? '—',
                    $shortfalls[$itemId] ?? 0,
                ])
                ->sortBy(fn (array $row): string => (string) $row[0])
                ->values()
                ->all(),
        );

        $prefix = $dryRun ? '[dry-run] Would update ' : 'Updated ';
        $this->info($prefix.$legacy->count().' issuance row(s); '.$splitRows.' extra row(s) from lot splits.');

        if ($shortfalls !== []) {
            $this->warn(array_sum($shortfalls).' issued unit(s) were not covered by regional supply lots and keep their original unit cost.');
        }

        return self::SUCCESS;
    }

    /**
     * Semi/PPE rows follow the unit cost of units already linked to them; otherwise walk supply lots oldest first.
     *
     * @return list<array{unit_cost: ?float, quantity: int, unit_ids: list<int>}>
     */
    protected function allocate(InventoryStockService $stock, Issuance $issuance, int $supplyOfficeId): array
    {
        $needed = (int) $issuance->quantity;
        $parts = [];

        $linkedByCost = InventoryUnit::query()
            ->where('issuance_id', $issuance->id)
            ->orderBy('id')
            ->get(['id', 'unit_cost'])
            ->groupBy(fn (InventoryUnit $unit): string => UnitCostKey::normalize($unit->unit_cost !== null ? (float) $unit->unit_cost : null));

        foreach ($linkedByCost as $costKey => $units) {
            if ($needed <= 0) {
                break;
            }

            $take = min($needed, $units->count());
            $parts[] = [
                'unit_cost' => UnitCostKey::toFloat((string) $costKey),
                'quantity' => $take,
                'unit_ids' => $units->take($take)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            ];
            $needed -= $take;
        }

        if ($needed <= 0) {
            return $parts;
        }

        foreach ($stock->availableLotsForIssue((int) $issuance->item_id, $supplyOfficeId, capByUnits: false) as $lot) {
            if ($needed <= 0) {
                break;
            }

            $take = min($needed, $lot['quantity']);
            $parts[] = ['unit_cost' => $lot['unit_cost'], 'quantity' => $take, 'unit_ids' => []];
            $needed -= $take;
        }

        return $parts;
    }

    /**
     * Restore the row with its first part and clone it for any further lots.
     *
     * @param  list<array{unit_cost: ?float, quantity: int, unit_ids: list<int>}>  $parts
     */
    protected function writeParts(Issuance $issuance, array $parts, int $supplyOfficeId): int
    {
        $clones = 0;

        foreach (array_values($parts) as $index => $part) {
            $values = [
                'issued_from_office_id' => $supplyOfficeId,
                'unit_cost' => $part['unit_cost'],
                'quantity' => $part['quantity'],
                'amount' => $part['unit_cost'] !== null ? $part['quantity'] * $part['unit_cost'] : null,
                'deleted_at' => null,
            ];

            if ($index === 0) {
                Issuance::withTrashed()->whereKey($issuance->id)->toBase()->update($values);

                continue;
            }

            $clone = $issuance->replicate();
            $clone->forceFill($values)->saveQuietly();
            $clones++;

            if ($part['unit_ids'] !== []) {
                InventoryUnit::query()->whereKey($part['unit_ids'])->update(['issuance_id' => $clone->id]);
            }
        }

        return $clones;
    }

    /**
     * @param  list<int>  $itemIds
     * @return array<int, array{stock: int, units: ?int}>
     */
    protected function stockSnapshot(InventoryStockService $stock, array $itemIds, int $supplyOfficeId): array
    {
        $stock->forgetMovementTotalsCache();

        $tracksUnits = Item::query()
            ->with('category')
            ->whereKey($itemIds)
            ->get()
            ->mapWithKeys(fn (Item $item): array => [
                $item->id => in_array($item->category?->getTemplateSlug(), ['ppe', 'semi_expendable'], true),
            ]);

        $unitCounts = InventoryUnit::query()
            ->whereIn('item_id', $itemIds)
            ->where('office_id', $supplyOfficeId)
            ->where('status', InventoryUnit::STATUS_IN_STOCK)
            ->selectRaw('item_id, COUNT(*) as total')
            ->groupBy('item_id')
            ->pluck('total', 'item_id');

        $snapshot = [];

        foreach ($itemIds as $itemId) {
            $snapshot[$itemId] = [
                'stock' => $stock->getStock($itemId, $supplyOfficeId),
                'units' => ($tracksUnits[$itemId] ?? false) ? (int) ($unitCounts[$itemId] ?? 0) : null,
            ];
        }

        return $snapshot;
    }
}
