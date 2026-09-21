<?php

namespace App\Services;

use App\Models\Disposal;
use App\Models\DisposalBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DisposalWorkflowService
{
    public function confirm(Disposal $disposal): DisposalBatch
    {
        $disposal->loadMissing(['batch.lines.item.category', 'batch.lines.inventoryUnit']);

        $batch = $disposal->batch;
        if ($batch === null) {
            throw new RuntimeException('Disposal batch is missing.');
        }

        if ($batch->confirmed_at !== null) {
            return $batch;
        }

        $validator = app(DisposalStockValidator::class);

        try {
            $batch->lines->each(fn (Disposal $line): mixed => $validator->validateRecord($line));
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first()
                ?? 'Stock or inventory unit validation failed.';

            throw new RuntimeException($message, 0, $exception);
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
