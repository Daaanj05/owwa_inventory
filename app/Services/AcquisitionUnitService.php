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
        protected ReferenceCodeService $referenceCodes,
        protected SemiExpendablePropertyNumberBuilder $semiBuilder,
    ) {}

    /**
     * @return array<int, InventoryUnit>
     */
    public function generateUnitsForAcquisition(Acquisition $acquisition): array
    {
        $acquisition->loadMissing(['item.category', 'office']);

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

            for ($i = $existing; $i < $quantity; $i++) {
                $propertyNumber = $slug === 'semi_expendable'
                    ? $this->semiBuilder->assignForAcquisition($acquisition)
                    : $this->referenceCodes->forPropertyNumber($slug);

                $units[] = InventoryUnit::query()->create([
                    'property_number' => $propertyNumber,
                    'acquisition_id' => $acquisition->id,
                    'item_id' => $item->id,
                    'office_id' => $acquisition->office_id,
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
