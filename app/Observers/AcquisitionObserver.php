<?php

namespace App\Observers;

use App\Models\Acquisition;
use App\Models\Item;
use App\Services\AcquisitionUnitService;
use App\Services\ReferenceCodeService;
use App\Support\PpeValueCategory;
use App\Support\SemiExpendableValueCategory;

class AcquisitionObserver
{
    public function creating(Acquisition $acquisition): void
    {
        if (empty($acquisition->reference_code)) {
            $acquisition->reference_code = app(ReferenceCodeService::class)->forAcquisition();
        }
        if (empty($acquisition->recorded_by) && auth()->check()) {
            $acquisition->recorded_by = auth()->id();
        }
    }

    public function saving(Acquisition $acquisition): void
    {
        $item = $acquisition->relationLoaded('item')
            ? $acquisition->item
            : ($acquisition->item_id ? Item::with('category')->find($acquisition->item_id) : null);

        $slug = $item?->category?->getTemplateSlug();

        if ($slug === 'semi_expendable') {
            if ($acquisition->unit_cost !== null) {
                SemiExpendableValueCategory::assertWithinSemiCap((float) $acquisition->unit_cost);
            }

            return;
        }

        if ($slug === 'ppe' && $acquisition->unit_cost !== null) {
            PpeValueCategory::assertMinimumForPpe((float) $acquisition->unit_cost);
        }
    }

    public function saved(Acquisition $acquisition): void
    {
        $item = $acquisition->relationLoaded('item')
            ? $acquisition->item
            : ($acquisition->item_id ? Item::with('category')->find($acquisition->item_id) : null);

        if ($item?->category?->getTemplateSlug() !== 'semi_expendable' || $acquisition->unit_cost === null) {
            return;
        }

        $item->update([
            'value_type' => SemiExpendableValueCategory::valueTypeForUnitCost((float) $acquisition->unit_cost),
        ]);
    }

    public function created(Acquisition $acquisition): void
    {
        $acquisition->loadMissing(['item.category']);

        if (! app(AcquisitionUnitService::class)->supportsUnitGeneration($acquisition->item)) {
            return;
        }

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);
    }
}
