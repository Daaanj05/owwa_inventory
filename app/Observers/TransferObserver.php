<?php

namespace App\Observers;

use App\Models\StockPositionRestockFlag;
use App\Models\Transfer;
use App\Services\InventoryStockService;
use App\Services\PropertyReturnService;
use App\Services\ReferenceCodeService;
use App\Services\TransferInventoryUnitService;
use App\Services\TransferUcNotificationService;

class TransferObserver
{
    public function creating(Transfer $transfer): void
    {
        if (empty($transfer->reference_code)) {
            $transfer->reference_code = app(ReferenceCodeService::class)->forTransfer();
        }
        if (empty($transfer->recorded_by) && auth()->check()) {
            $transfer->recorded_by = auth()->id();
        }
    }

    public function created(Transfer $transfer): void
    {
        app(InventoryStockService::class)->forgetMovementTotalsCache();
        app(PropertyReturnService::class)->processReturnTransfer($transfer);
        app(TransferInventoryUnitService::class)->syncUnitsForTransfer($transfer);
        app(TransferUcNotificationService::class)->notifyDestinationUnitConsolidators($transfer);
    }

    public function saved(Transfer $transfer): void
    {
        app(InventoryStockService::class)->forgetMovementTotalsCache();

        if ((int) ($transfer->quantity ?? 0) <= 0 || ! $transfer->to_office_id) {
            return;
        }

        StockPositionRestockFlag::reactivateOnAcquisition(
            (int) $transfer->item_id,
            (int) $transfer->to_office_id,
            $transfer->unit_cost !== null ? (float) $transfer->unit_cost : null,
        );
    }

    public function deleted(Transfer $transfer): void
    {
        app(InventoryStockService::class)->forgetMovementTotalsCache();
    }

    public function restored(Transfer $transfer): void
    {
        app(InventoryStockService::class)->forgetMovementTotalsCache();
    }
}
