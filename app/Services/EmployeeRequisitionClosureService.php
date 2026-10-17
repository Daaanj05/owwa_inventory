<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\Requisition;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Support\EmployeeRequisitionStatus;

class EmployeeRequisitionClosureService
{
    public function closeFromIssuance(Issuance $issuance): void
    {
        $issuance->loadMissing(['requisition.requestedBy', 'issuedTo']);

        $requisition = $this->resolveEmployeeRequisitionFromIssuance($issuance);

        if (! $requisition instanceof Requisition) {
            return;
        }

        $this->closeIfFulfilled($requisition);
    }

    public function closeFromRequisition(Requisition $requisition): void
    {
        if (! $requisition->isEmployeeRequest()) {
            return;
        }

        $this->closeIfFulfilled($requisition);
    }

    protected function closeIfFulfilled(Requisition $requisition): void
    {
        if ($requisition->closed_at !== null) {
            return;
        }

        $target = EmployeeRequisitionStatus::fulfillmentTargetTotal($requisition);
        $issued = EmployeeRequisitionStatus::issuedTotal($requisition);

        if ($target <= 0 || $issued < $target) {
            return;
        }

        $this->finalizeClosure($requisition, $issued, $target);
    }

    protected function finalizeClosure(Requisition $requisition, int $fulfilled, int $target): void
    {
        $requisition->update([
            'closed_at' => now(),
            'fulfillment_summary' => EmployeeRequisitionStatus::formatSummary($fulfilled, $target),
        ]);

        $employee = $requisition->requestedBy;

        if (! $employee instanceof User) {
            return;
        }

        $reference = $requisition->displayTransactionNumber() ?? 'your request';
        $body = sprintf(
            '%s is closed. %s. File a new requisition for any remaining need.',
            $reference,
            $requisition->fulfillment_summary,
        );

        $employee->notify(new RequisitionWorkflowDatabaseNotification(
            'Requisition closed',
            $body,
            (int) $requisition->id,
        ));
    }

    protected function resolveEmployeeRequisitionFromIssuance(Issuance $issuance): ?Requisition
    {
        $requisition = $issuance->requisition;

        if ($requisition?->isEmployeeRequest()) {
            return $requisition;
        }

        return null;
    }
}
