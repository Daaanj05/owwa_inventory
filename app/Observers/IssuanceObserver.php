<?php

namespace App\Observers;

use App\Events\IssuanceChanged;
use App\Models\Issuance;
use App\Models\Item;
use App\Services\InventoryStockService;
use App\Services\IssuanceNotificationService;
use App\Services\IssuanceUnitAssignmentService;
use App\Services\ReferenceCodeService;
use App\Support\SemiExpendableUsefulLife;
use App\Support\SemiExpendableValueCategory;
use Illuminate\Validation\ValidationException;

class IssuanceObserver
{
    public function creating(Issuance $issuance): void
    {
        if (blank($issuance->requisition_id)) {
            throw new \InvalidArgumentException('Issuance must be linked to a requisition. Use Requisitions → Accept & issue.');
        }

        if (filled($issuance->issuance_batch_id)) {
            $issuance->loadMissing('batch');
            if (filled($issuance->batch?->reference_code)) {
                $issuance->reference_code = $issuance->batch->reference_code;
            }
        } elseif (empty($issuance->reference_code)) {
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

        if (config('inventory.auto_generate_property_numbers', true)
            && blank($issuance->property_number)
            && in_array($slug, ['semi_expendable', 'ppe'], true)) {
            throw ValidationException::withMessages([
                'property_number' => 'No in-stock inventory unit with an assigned property or inventory number. Record acquisition first.',
            ]);
        }

        if (empty($issuance->issued_by) && auth()->check()) {
            $issuance->issued_by = auth()->id();
        }
    }

    public function created(Issuance $issuance): void
    {
        app(InventoryStockService::class)->forgetMovementTotalsCache();
        app(IssuanceNotificationService::class)->handleCreated($issuance);

        if (filled(config('filament.broadcasting.echo.key'))) {
            IssuanceChanged::dispatch($issuance);
        }
    }

    public function updated(Issuance $issuance): void
    {
        app(InventoryStockService::class)->forgetMovementTotalsCache();
    }

    public function deleted(Issuance $issuance): void
    {
        app(InventoryStockService::class)->forgetMovementTotalsCache();
    }

    public function restored(Issuance $issuance): void
    {
        app(InventoryStockService::class)->forgetMovementTotalsCache();
    }

    public function saving(Issuance $issuance): void
    {
        if (! $issuance->relationLoaded('item') && $issuance->item_id) {
            $issuance->load('item.category');
        }

        SemiExpendableUsefulLife::syncExpiresAt($issuance);
    }
}
