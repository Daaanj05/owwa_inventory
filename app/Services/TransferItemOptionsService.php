<?php

namespace App\Services;

use App\Models\Item;

class TransferItemOptionsService
{
    public function __construct(
        private InventoryStockService $stockService,
    ) {}

    /**
     * @return array<int, string>
     */
    public function optionsForFromOffice(int $fromOfficeId, ?int $categoryId): array
    {
        $query = Item::query()
            ->active()
            ->orderBy('name');

        if ($categoryId !== null) {
            $query->where('item_category_id', $categoryId);
        }

        $options = [];

        foreach ($query->get(['id', 'name']) as $item) {
            if (! $this->stockService->hasInventoryActivity($item->id, $fromOfficeId)) {
                continue;
            }

            $stock = $this->stockService->getStock($item->id, $fromOfficeId);
            $options[$item->id] = sprintf('%s (%d available)', $item->name, max(0, $stock));
        }

        return $options;
    }

    public function availableStock(int $itemId, int $fromOfficeId): int
    {
        return max(0, $this->stockService->getStock($itemId, $fromOfficeId));
    }
}
