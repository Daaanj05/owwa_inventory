<?php

namespace App\Support;

use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InventoryCategoryUnitCost
{
    /**
     * @throws ValidationException
     */
    public static function assertForTemplateSlug(?string $templateSlug, ?float $unitCost): void
    {
        if ($unitCost === null || $templateSlug === null || $templateSlug === '') {
            return;
        }

        if ($templateSlug === 'semi_expendable') {
            SemiExpendableValueCategory::assertWithinSemiCap($unitCost);

            return;
        }

        if ($templateSlug === 'ppe') {
            PpeValueCategory::assertMinimumForPpe($unitCost);
        }
    }

    /**
     * @throws ValidationException
     */
    public static function assertForItem(?Item $item, ?float $unitCost): void
    {
        $item?->loadMissing('category');

        self::assertForTemplateSlug($item?->category?->getTemplateSlug(), $unitCost);
    }

    public static function isOutOfRangeForItem(?Item $item, ?float $unitCost): bool
    {
        if ($unitCost === null || $item === null) {
            return false;
        }

        try {
            self::assertForItem($item, $unitCost);
        } catch (ValidationException) {
            return true;
        }

        return false;
    }

    public static function isOutOfRangeForTemplateSlug(?string $templateSlug, ?float $unitCost): bool
    {
        if ($unitCost === null || $templateSlug === null || $templateSlug === '') {
            return false;
        }

        try {
            self::assertForTemplateSlug($templateSlug, $unitCost);
        } catch (ValidationException) {
            return true;
        }

        return false;
    }

    /**
     * @throws ValidationException
     */
    public static function assertPurchaseOrder(PurchaseOrder $purchaseOrder): void
    {
        $purchaseOrder->loadMissing(['lines.item.category']);

        foreach ($purchaseOrder->orderedLines()->with('item.category')->get() as $line) {
            /** @var PurchaseOrderLine $line */
            if ($line->unit_cost === null || (float) $line->unit_cost <= 0) {
                continue;
            }

            try {
                self::assertForItem($line->item, (float) $line->unit_cost);
            } catch (ValidationException $exception) {
                $message = $exception->validator->errors()->first('unit_cost')
                    ?? 'Invalid unit cost for this category.';

                throw ValidationException::withMessages([
                    'unit_cost' => $message,
                ]);
            }
        }
    }

    public static function purchaseOrderHasOutOfRangeCost(PurchaseOrder $purchaseOrder): bool
    {
        $purchaseOrder->loadMissing(['lines.item.category']);

        return $purchaseOrder->lines
            ->filter(fn (PurchaseOrderLine $line): bool => $line->is_ordered && (int) $line->po_quantity > 0)
            ->contains(function (PurchaseOrderLine $line): bool {
                if ($line->unit_cost === null || (float) $line->unit_cost <= 0) {
                    return false;
                }

                return self::isOutOfRangeForItem($line->item, (float) $line->unit_cost);
            });
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineStates
     */
    public static function lineStatesHaveOutOfRangeCost(array $lineStates): bool
    {
        $itemIds = collect($lineStates)
            ->map(fn (array $line): int => (int) ($line['item_id'] ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($itemIds === []) {
            return false;
        }

        /** @var Collection<int, Item> $items */
        $items = Item::query()
            ->with('category')
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        foreach ($lineStates as $line) {
            $ordered = (bool) ($line['is_ordered'] ?? true);
            $quantity = (int) ($line['po_quantity'] ?? 0);
            $unitCost = $line['unit_cost'] ?? null;
            $itemId = (int) ($line['item_id'] ?? 0);

            if (! $ordered || $quantity < 1 || blank($unitCost) || (float) $unitCost <= 0 || $itemId < 1) {
                continue;
            }

            $item = $items->get($itemId);
            if (self::isOutOfRangeForItem($item, (float) $unitCost)) {
                return true;
            }
        }

        return false;
    }
}
