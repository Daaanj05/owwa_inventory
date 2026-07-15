<?php

namespace App\Observers;

use App\Models\Disposal;
use App\Models\DisposalBatch;
use App\Services\DisposalInventoryUnitService;

class DisposalObserver
{
    public function creating(Disposal $disposal): void
    {
        if (blank($disposal->disposal_batch_id)) {
            $disposal->loadMissing('item.category');
            $categorySlug = $disposal->item?->category?->getTemplateSlug() ?? 'consumables';

            $batch = DisposalBatch::create([
                'category_slug' => $categorySlug,
                'office_id' => $disposal->office_id,
                'department_id' => $disposal->department_id,
                'disposal_date' => $disposal->disposal_date,
                'disposal_type' => $disposal->disposal_type,
                'disposal_mode' => $disposal->disposal_mode,
                'remarks' => $disposal->remarks,
                'recorded_by' => $disposal->recorded_by,
                'custodian_printed_name' => $disposal->custodian_printed_name,
                'accountable_officer_designation' => $disposal->accountable_officer_designation,
                'accountable_officer_station' => $disposal->accountable_officer_station,
                'approved_by_printed_name' => $disposal->approved_by_printed_name,
                'immediate_supervisor_printed_name' => $disposal->immediate_supervisor_printed_name,
                'inspection_officer_printed_name' => $disposal->inspection_officer_printed_name,
                'witness_printed_name' => $disposal->witness_printed_name,
            ]);

            $disposal->disposal_batch_id = $batch->id;
            $disposal->reference_code = $batch->reference_code;
        } elseif (empty($disposal->reference_code)) {
            $disposal->loadMissing('batch');
            if (filled($disposal->batch?->reference_code)) {
                $disposal->reference_code = $disposal->batch->reference_code;
            }
        }

        if (empty($disposal->recorded_by) && auth()->check()) {
            $disposal->recorded_by = auth()->id();
        }
    }

    public function created(Disposal $disposal): void
    {
        app(DisposalInventoryUnitService::class)->markUnitDisposed($disposal);
    }
}
