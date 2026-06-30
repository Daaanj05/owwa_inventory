<?php

namespace App\Observers;

use App\Events\RequisitionChanged;
use App\Models\Requisition;
use App\Services\ReferenceCodeService;
use App\Services\RequisitionWorkflowNotificationService;

class RequisitionObserver
{
    public function creating(Requisition $requisition): void
    {
        if (empty($requisition->reference_code)) {
            $requisition->reference_code = app(ReferenceCodeService::class)->forRequisition();
        }
        if (empty($requisition->requested_by) && auth()->check()) {
            $requisition->requested_by = auth()->id();
        }
    }

    public function created(Requisition $requisition): void
    {
        RequisitionChanged::dispatch($requisition, 'created');
        app(RequisitionWorkflowNotificationService::class)->handleCreated($requisition);
    }

    public function updating(Requisition $requisition): void
    {
        if ($requisition->isDirty('status')) {
            $requisition->statusBeforeUpdate = $requisition->getOriginal('status');
        }
    }

    public function updated(Requisition $requisition): void
    {
        RequisitionChanged::dispatch($requisition, 'updated');
        app(RequisitionWorkflowNotificationService::class)->handleUpdated(
            $requisition,
            $requisition->statusBeforeUpdate,
        );
    }
}
