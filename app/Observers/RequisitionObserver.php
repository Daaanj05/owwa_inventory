<?php

namespace App\Observers;

use App\Events\RequisitionChanged;
use App\Models\Requisition;
use App\Models\User;
use App\Services\ReferenceCodeService;
use App\Services\RequisitionWorkflowNotificationService;
use Illuminate\Broadcasting\BroadcastException;

class RequisitionObserver
{
    public function creating(Requisition $requisition): void
    {
        if (empty($requisition->requested_by) && auth()->check()) {
            $requisition->requested_by = auth()->id();
        }

        $requester = $requisition->requested_by
            ? User::query()->find($requisition->requested_by)
            : null;

        $referenceCodeService = app(ReferenceCodeService::class);

        if ($requester?->isEmployee()) {
            if (blank($requisition->transaction_number) && filled($requisition->reference_code)) {
                $requisition->transaction_number = $requisition->reference_code;
            }

            if (blank($requisition->transaction_number)) {
                $requisition->transaction_number = $referenceCodeService->forEmployeeRequisitionTransaction();
            }

            $requisition->reference_code = null;
        } elseif (empty($requisition->reference_code)) {
            $requisition->reference_code = $referenceCodeService->forRequisition();
        }
    }

    public function created(Requisition $requisition): void
    {
        $this->broadcastRequisitionChanged($requisition, 'created');
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
        $this->broadcastRequisitionChanged($requisition, 'updated');
        app(RequisitionWorkflowNotificationService::class)->handleUpdated(
            $requisition,
            $requisition->statusBeforeUpdate,
        );
    }

    protected function broadcastRequisitionChanged(Requisition $requisition, string $action): void
    {
        try {
            RequisitionChanged::dispatch($requisition, $action);
        } catch (BroadcastException $exception) {
            report($exception);
        }
    }
}
