<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\Transfer;

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

    /**
     * Issued property still held from the selected office, labeled with the employee and property number.
     *
     * @return array<int, string>
     */
    public function issuedOptionsForOffice(int $fromOfficeId, ?int $categoryId): array
    {
        $issuances = Issuance::query()
            ->with(['item', 'issuedTo'])
            ->where('office_id', $fromOfficeId)
            ->whereNull('custody_ended_at')
            ->whereHas('item', function ($query) use ($categoryId): void {
                $query->active();

                if ($categoryId !== null) {
                    $query->where('item_category_id', $categoryId);
                }
            })
            ->orderByDesc('issuance_date')
            ->orderByDesc('id')
            ->get();

        $options = [];

        foreach ($issuances as $issuance) {
            $propertyNumber = filled($issuance->property_number) ? (string) $issuance->property_number : null;
            $remaining = $this->stillIssuedQuantity((int) $issuance->item_id, $fromOfficeId, $propertyNumber);

            if ($remaining < 1) {
                continue;
            }

            $employee = $issuance->issuedTo?->name ?? 'Unassigned';
            $property = $propertyNumber ?? 'No property number';
            $itemName = $issuance->item?->name ?? 'Item';
            $options[$issuance->id] = sprintf('%s — %s — %s (%d issued)', $itemName, $employee, $property, $remaining);
        }

        return $options;
    }

    public function availableStock(int $itemId, int $fromOfficeId): int
    {
        return max(0, $this->stockService->getStock($itemId, $fromOfficeId));
    }

    public function stillIssuedQuantity(int $itemId, int $fromOfficeId, ?string $propertyNumber, ?Transfer $existing = null): int
    {
        $issuedQuery = Issuance::query()
            ->where('item_id', $itemId)
            ->where('office_id', $fromOfficeId)
            ->whereNull('custody_ended_at');

        $returnedQuery = Transfer::query()
            ->where('transfer_type', Transfer::TYPE_RETURN)
            ->where('item_id', $itemId)
            ->where('from_office_id', $fromOfficeId);

        if (filled($propertyNumber)) {
            $issuedQuery->where('property_number', $propertyNumber);
            $returnedQuery->where('property_number', $propertyNumber);
        } else {
            $blankProperty = function ($query): void {
                $query->whereNull('property_number')->orWhere('property_number', '');
            };
            $issuedQuery->where($blankProperty);
            $returnedQuery->where($blankProperty);
        }

        if ($existing !== null) {
            $returnedQuery->whereKeyNot($existing->id);
        }

        return max(0, (int) $issuedQuery->sum('quantity') - (int) $returnedQuery->sum('quantity'));
    }
}
