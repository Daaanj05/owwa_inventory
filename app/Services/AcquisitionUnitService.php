<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\InventoryUnit;
use App\Models\Item;
use App\Support\SemiExpendableValueCategory;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AcquisitionUnitService
{
    public function __construct(
        protected CatalogAssetNumberService $catalogNumbers,
        protected SemiExpendablePropertyNumberBuilder $semiBuilder,
    ) {}

    /**
     * @return array<int, InventoryUnit>
     */
    public function generateUnitsForAcquisition(Acquisition $acquisition): array
    {
        $acquisition->loadMissing(['item.category', 'item.uacsObjectCode', 'office']);

        $slug = $acquisition->item?->category?->getTemplateSlug();
        if (! in_array($slug, ['ppe', 'semi_expendable'], true)) {
            return [];
        }

        $quantity = max(0, (int) $acquisition->quantity);
        if ($quantity === 0) {
            return [];
        }

        $existing = $acquisition->inventoryUnits()->count();
        if ($existing >= $quantity) {
            return $acquisition->inventoryUnits()->orderBy('id')->get()->all();
        }

        $item = $acquisition->item;
        if ($item === null) {
            return [];
        }

        $created = $this->mintUnitsForItem(
            item: $item,
            officeId: (int) $acquisition->office_id,
            quantity: $quantity - $existing,
            unitCost: $acquisition->unit_cost !== null ? (float) $acquisition->unit_cost : null,
            acquisitionId: $acquisition->id,
        );

        return $acquisition->inventoryUnits()->orderBy('id')->get()->all() ?: $created;
    }

    /**
     * Mint inventory units with a shared catalog property number.
     *
     * @return array<int, InventoryUnit>
     */
    public function mintUnitsForItem(
        Item $item,
        int $officeId,
        int $quantity,
        ?float $unitCost,
        ?int $acquisitionId = null,
    ): array {
        $item->loadMissing(['category', 'uacsObjectCode']);

        $slug = $item->category?->getTemplateSlug();
        if (! in_array($slug, ['ppe', 'semi_expendable'], true) || $quantity < 1) {
            return [];
        }

        $units = [];

        DB::transaction(function () use ($item, $officeId, $quantity, $unitCost, $acquisitionId, $slug, &$units): void {
            $propertyNumber = $this->resolveCatalogPropertyNumberForItem($item, $slug, $unitCost);

            for ($i = 0; $i < $quantity; $i++) {
                $units[] = InventoryUnit::query()->create([
                    'property_number' => $propertyNumber,
                    'acquisition_id' => $acquisitionId,
                    'item_id' => $item->id,
                    'office_id' => $officeId,
                    'unit_cost' => $unitCost,
                    'status' => InventoryUnit::STATUS_IN_STOCK,
                    'article' => $item->name,
                    'description' => $item->description,
                    'stock_number' => $item->item_code,
                    'unit_of_measure' => $item->unit,
                ]);
            }
        });

        return $units;
    }

    protected function resolveCatalogPropertyNumberForItem(Item $item, string $slug, ?float $unitCost): string
    {
        if ($slug === 'semi_expendable') {
            $number = $this->catalogNumbers->finalizeSemiWithUnitCost($item, $unitCost);
            $this->semiBuilder->persistBucketPropertyNumber($item, $unitCost, $number);

            InventoryUnit::query()
                ->where('item_id', $item->id)
                ->where('property_number', 'like', 'TEMP-%')
                ->update(['property_number' => $number]);

            return $number;
        }

        $number = $item->ppe_property_number;
        if (blank($number)) {
            $number = $this->catalogNumbers->mintPpe($item);
            $item->forceFill(['ppe_property_number' => $number])->saveQuietly();
        }

        return (string) $number;
    }

    public function supportsUnitGeneration(?Item $item): bool
    {
        $slug = $item?->category?->getTemplateSlug();

        return in_array($slug, ['ppe', 'semi_expendable'], true);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function assertEligible(Acquisition $acquisition): void
    {
        $acquisition->loadMissing(['item.category']);

        if (! $this->supportsUnitGeneration($acquisition->item)) {
            throw new InvalidArgumentException('Inventory units are only generated for PPE and semi-expendable acquisitions.');
        }

        if ($acquisition->item?->category?->getTemplateSlug() === 'semi_expendable' && $acquisition->unit_cost !== null) {
            SemiExpendableValueCategory::assertWithinSemiCap((float) $acquisition->unit_cost);
        }
    }
}
