<?php

namespace App\Console\Commands;

use App\Models\Acquisition;
use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\ItemStockBucket;
use App\Models\Transfer;
use App\Services\SemiExpendablePropertyNumberBuilder;
use App\Support\UnitCostKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillStockBuckets extends Command
{
    protected $signature = 'inventory:backfill-stock-buckets {--dry-run : Report only, do not write}';

    protected $description = 'Backfill item stock buckets, unit costs on units/transfers, and semi property numbers per cost bucket';

    public function handle(SemiExpendablePropertyNumberBuilder $semiBuilder): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $bucketsCreated = 0;
        $unitsUpdated = 0;
        $transfersUpdated = 0;
        $propertyFixed = 0;
        $unresolvedTransfers = 0;

        $distinctCosts = Acquisition::query()
            ->select('item_id', 'unit_cost')
            ->distinct()
            ->get();

        foreach ($distinctCosts as $row) {
            $normalized = UnitCostKey::normalize($row->unit_cost !== null ? (float) $row->unit_cost : null);
            $exists = ItemStockBucket::query()
                ->where('item_id', $row->item_id)
                ->where('unit_cost', $normalized)
                ->exists();

            if ($exists) {
                continue;
            }

            if (! $dryRun) {
                ItemStockBucket::query()->create([
                    'item_id' => $row->item_id,
                    'unit_cost' => $normalized,
                ]);
            }
            $bucketsCreated++;
        }

        $semiItems = Item::query()
            ->whereNotNull('semi_expendable_property_number')
            ->with(['category'])
            ->get()
            ->filter(fn (Item $item): bool => $item->category?->getTemplateSlug() === 'semi_expendable');

        foreach ($semiItems as $item) {
            $costGroups = Acquisition::query()
                ->where('item_id', $item->id)
                ->whereNotNull('unit_cost')
                ->orderBy('acquisition_date')
                ->orderBy('id')
                ->get()
                ->groupBy(fn (Acquisition $a): string => UnitCostKey::normalize((float) $a->unit_cost));

            $first = true;
            foreach ($costGroups as $costKey => $acquisitions) {
                $bucket = ItemStockBucket::findForItemCost((int) $item->id, UnitCostKey::toFloat($costKey))
                    ?? ItemStockBucket::firstOrCreateForItemCost((int) $item->id, UnitCostKey::toFloat($costKey));

                if ($first && filled($item->semi_expendable_property_number)) {
                    if (! $dryRun && blank($bucket->property_number)) {
                        $bucket->update(['property_number' => $item->semi_expendable_property_number]);
                    }
                    $first = false;

                    continue;
                }

                if (filled($bucket->property_number)) {
                    continue;
                }

                $acquisition = $acquisitions->first();
                if ($acquisition === null) {
                    continue;
                }

                if (! $dryRun) {
                    $number = $semiBuilder->resolveOrAssignForAcquisition($acquisition->fresh(['item.category', 'office']));
                    $bucket->refresh();
                    if (blank($bucket->property_number)) {
                        $bucket->update(['property_number' => $number]);
                    }
                }
                $propertyFixed++;
            }
        }

        $units = InventoryUnit::query()->whereNull('unit_cost')->with('acquisition')->get();
        foreach ($units as $unit) {
            $cost = $unit->acquisition?->unit_cost;
            if ($cost === null) {
                continue;
            }

            if (! $dryRun) {
                $unit->update(['unit_cost' => $cost]);
            }
            $unitsUpdated++;
        }

        $transfers = Transfer::query()->whereNull('unit_cost')->get();
        foreach ($transfers as $transfer) {
            $cost = null;

            if (filled($transfer->property_number)) {
                $cost = InventoryUnit::query()
                    ->where('property_number', $transfer->property_number)
                    ->where('item_id', $transfer->item_id)
                    ->value('unit_cost');
            }

            if ($cost === null) {
                $cost = Acquisition::query()
                    ->where('item_id', $transfer->item_id)
                    ->where('office_id', $transfer->from_office_id)
                    ->whereNotNull('unit_cost')
                    ->orderBy('acquisition_date')
                    ->value('unit_cost');
            }

            if ($cost === null) {
                $unresolvedTransfers++;

                continue;
            }

            if (! $dryRun) {
                $transfer->update(['unit_cost' => $cost]);
            }
            $transfersUpdated++;
        }

        if (! $dryRun) {
            DB::table('issuances')
                ->whereNull('unit_cost')
                ->whereNotNull('property_number')
                ->orderBy('id')
                ->chunkById(100, function ($issuances): void {
                    foreach ($issuances as $issuance) {
                        $cost = InventoryUnit::query()
                            ->where('property_number', $issuance->property_number)
                            ->where('item_id', $issuance->item_id)
                            ->value('unit_cost');

                        if ($cost !== null) {
                            DB::table('issuances')->where('id', $issuance->id)->update(['unit_cost' => $cost]);
                        }
                    }
                });
        }

        $this->info("Buckets created: {$bucketsCreated}");
        $this->info("Semi property numbers assigned/fixed: {$propertyFixed}");
        $this->info("Inventory units unit_cost backfilled: {$unitsUpdated}");
        $this->info("Transfers unit_cost backfilled: {$transfersUpdated}");
        if ($unresolvedTransfers > 0) {
            $this->warn("Transfers without inferrable unit_cost: {$unresolvedTransfers}");
        }

        if ($dryRun) {
            $this->comment('Dry run — no changes written.');
        }

        return self::SUCCESS;
    }
}
