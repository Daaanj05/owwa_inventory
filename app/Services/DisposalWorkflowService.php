<?php

namespace App\Services;

use App\Models\Disposal;
use App\Models\DisposalBatch;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DisposalWorkflowService
{
    public function confirm(Disposal $disposal): DisposalBatch
    {
        $disposal->loadMissing(['batch.lines']);

        $batch = $disposal->batch;
        if ($batch === null) {
            throw new RuntimeException('Disposal batch is missing.');
        }

        if ($batch->confirmed_at !== null) {
            return $batch;
        }

        return DB::transaction(function () use ($batch): DisposalBatch {
            $batch->forceFill(['confirmed_at' => now()])->save();

            $batch->lines()->with(['item', 'inventoryUnit'])->each(function (Disposal $line): void {
                app(DisposalInventoryUnitService::class)->markUnitDisposed($line);
            });

            app(InventoryStockService::class)->forgetMovementTotalsCache();

            return $batch->fresh() ?? $batch;
        });
    }
}
