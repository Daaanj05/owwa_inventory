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

        $units = [];

        DB::transaction(function () use ($acquisition, $slug, $quantity, $existing, &$units): void {
            $item = $acquisition->item;
            if ($item === null) {
                return;
            }

            $propertyNumber = $this->resolveCatalogPropertyNumber($acquisition, $item, $slug);

            for ($i = $existing; $i < $quantity; $i++) {
                $units[] = InventoryUnit::query()->create([
                    'property_number' => $propertyNumber,
                    'acquisition_id' => $acquisition->id,
                    'item_id' => $item->id,
                    'office_id' => $acquisition->office_id,
                    'unit_cost' => $acquisition->unit_cost,
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

    protected function resolveCatalogPropertyNumber(Acquisition $acquisition, Item $item, string $slug): string
    {
        if ($slug === 'semi_expendable') {
            $unitCost = $acquisition->unit_cost !== null ? (float) $acquisition->unit_cost : null;
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
