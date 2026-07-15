<?php

namespace App\Services;

use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Support\SupplyOfficeResolver;

class RequisitionStockSnapshotService
{
    public function __construct(
        protected InventoryStockService $stockService,
        protected SupplyOfficeResolver $supplyOfficeResolver,
    ) {}

    public function regionalStockForItem(int $itemId): int
    {
        $supplyOfficeId = $this->supplyOfficeResolver->resolve();

        if ($supplyOfficeId === null) {
            return 0;
        }

        return max(0, $this->stockService->getStock($itemId, $supplyOfficeId));
    }

    public function snapshotLine(RequisitionItem $line): void
    {
        $line->update([
            'stock_at_request' => $this->regionalStockForItem((int) $line->item_id),
        ]);
    }

    public function snapshotRequisitionLines(Requisition $requisition): void
    {
        $requisition->loadMissing('items');

        foreach ($requisition->items as $line) {
            $this->snapshotLine($line);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function applyStockAtRequestToItemRows(array $items): array
    {
        return collect($items)
            ->map(function (array $row): array {
                $itemId = (int) ($row['item_id'] ?? 0);

                if ($itemId > 0) {
                    $row['stock_at_request'] = $this->regionalStockForItem($itemId);
                }

                return $row;
            })
            ->all();
    }
}
