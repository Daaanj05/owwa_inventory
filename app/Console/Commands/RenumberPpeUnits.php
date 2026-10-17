<?php

namespace App\Console\Commands;

use App\Models\InventoryUnit;
use App\Models\Item;
use App\Services\CatalogAssetNumberService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RenumberPpeUnits extends Command
{
    protected $signature = 'inventory:renumber-ppe-units {--dry-run : Report only, do not write}';

    protected $description = 'Give in-stock PPE units that share a Property No. their own number (oldest unit keeps the original)';

    public function handle(CatalogAssetNumberService $catalogNumbers): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $ppeItemIds = Item::query()
            ->with('category')
            ->get()
            ->filter(fn (Item $item): bool => $item->category?->getTemplateSlug() === 'ppe')
            ->pluck('id')
            ->all();

        if ($ppeItemIds === []) {
            $this->info('No PPE items found.');

            return self::SUCCESS;
        }

        $sharedNumbers = InventoryUnit::query()
            ->whereIn('item_id', $ppeItemIds)
            ->whereNotNull('property_number')
            ->groupBy('item_id', 'property_number')
            ->havingRaw('COUNT(*) > 1')
            ->get(['item_id', 'property_number']);

        $renumbered = 0;
        $rows = [];

        foreach ($sharedNumbers as $shared) {
            $units = InventoryUnit::query()
                ->where('item_id', $shared->item_id)
                ->where('property_number', $shared->property_number)
                ->orderBy('id')
                ->get();

            $toRenumber = $units->skip(1)
                ->filter(fn (InventoryUnit $unit): bool => $unit->status === InventoryUnit::STATUS_IN_STOCK)
                ->values();

            if ($toRenumber->isEmpty()) {
                continue;
            }

            $rows[] = [$shared->property_number, $units->count(), $toRenumber->count()];
            $renumbered += $toRenumber->count();

            if ($dryRun) {
                continue;
            }

            $item = Item::query()->with(['category', 'uacsObjectCode'])->find($shared->item_id);

            if ($item === null) {
                continue;
            }

            DB::transaction(function () use ($toRenumber, $catalogNumbers, $item): void {
                foreach ($toRenumber as $unit) {
                    $unit->update(['property_number' => $catalogNumbers->mintPpeForUnit($item)]);
                }
            });
        }

        if ($rows !== []) {
            $this->table(['Property No.', 'Units sharing it', 'In-stock units renumbered'], $rows);
        }

        $this->info(($dryRun ? '[dry-run] Would renumber ' : 'Renumbered ').$renumbered.' in-stock PPE unit(s).');

        return self::SUCCESS;
    }
}
