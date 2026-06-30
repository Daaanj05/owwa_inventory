<?php

namespace App\Observers;

use App\Models\Issuance;
use App\Models\Item;
use App\Services\IssuanceUnitAssignmentService;
use App\Services\ReferenceCodeService;
use App\Services\SemiExpendablePropertyNumberBuilder;
use App\Support\SemiExpendableUsefulLife;
use App\Support\SemiExpendableValueCategory;

class IssuanceObserver
{
    public function creating(Issuance $issuance): void
    {
        if (blank($issuance->requisition_id)) {
            throw new \InvalidArgumentException('Issuance must be linked to a requisition. Use Requisitions → Accept & issue.');
        }

        if (empty($issuance->reference_code)) {
            $issuance->reference_code = app(ReferenceCodeService::class)->forIssuance();
        }

        $item = $issuance->relationLoaded('item')
            ? $issuance->item
            : ($issuance->item_id ? Item::with('category')->find($issuance->item_id) : null);

        $slug = $item?->category?->getTemplateSlug();

        if ($slug === 'semi_expendable') {
            $unitCost = $issuance->unit_cost !== null
                ? (float) $issuance->unit_cost
                : null;

            SemiExpendableValueCategory::assertWithinSemiCap($unitCost);

            $builder = app(SemiExpendablePropertyNumberBuilder::class);
            $builder->assertDepartmentPresent($issuance);

            if ($item !== null && $unitCost !== null) {
                $item->update([
                    'value_type' => SemiExpendableValueCategory::valueTypeForUnitCost($unitCost),
                ]);
            }

            if (blank($issuance->estimated_useful_life)) {
                $issuance->estimated_useful_life = SemiExpendableUsefulLife::resolveForItem($item);
            }

            SemiExpendableUsefulLife::assertEligibleForSemi($issuance->estimated_useful_life);
        }

        if (config('inventory.auto_generate_property_numbers', true) && blank($issuance->property_number)) {
            app(IssuanceUnitAssignmentService::class)->assignUnitToIssuance($issuance);
        }

        if (config('inventory.auto_generate_property_numbers', true) && blank($issuance->property_number)) {
            if ($slug === 'semi_expendable') {
                $issuance->property_number = app(SemiExpendablePropertyNumberBuilder::class)
                    ->assignForIssuance($issuance);
            } elseif ($slug === 'ppe') {
                $issuance->property_number = app(ReferenceCodeService::class)->forPropertyNumber($slug);
            }
        }

        if (empty($issuance->issued_by) && auth()->check()) {
            $issuance->issued_by = auth()->id();
        }
    }

    public function saving(Issuance $issuance): void
    {
        if (! $issuance->relationLoaded('item') && $issuance->item_id) {
            $issuance->load('item.category');
        }

        SemiExpendableUsefulLife::syncExpiresAt($issuance);
    }
}
