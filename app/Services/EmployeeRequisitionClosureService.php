<?php

namespace App\Services;

use App\Models\Distribution;
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

    public function closeFromDistribution(Distribution $distribution): void
    {
        $distribution->loadMissing(['requisition.requestedBy', 'distributedTo']);

        $requisition = $this->resolveEmployeeRequisitionFromDistribution($distribution);

        if (! $requisition instanceof Requisition) {
            return;
        }

        if ($requisition->closed_at !== null) {
            return;
        }

        if (! $distribution->requisition_id) {
            $distribution->updateQuietly(['requisition_id' => $requisition->id]);
        }

        $target = EmployeeRequisitionStatus::fulfillmentTargetTotal($requisition);
        $distributed = EmployeeRequisitionStatus::distributedTotal($requisition);

        if ($target <= 0 || $distributed < $target) {
            return;
        }

        $this->finalizeClosure($requisition, $distributed, $target);
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

    protected function resolveEmployeeRequisitionFromDistribution(Distribution $distribution): ?Requisition
    {
        if ($distribution->requisition_id) {
            $requisition = $distribution->requisition;

            if ($requisition?->isEmployeeRequest()) {
                return $requisition;
            }
        }

        if (! $distribution->distributed_to || ! $distribution->item_id) {
            return null;
        }

        return Requisition::query()
            ->where('requested_by', $distribution->distributed_to)
            ->where('status', Requisition::STATUS_ACCEPTED)
            ->whereNull('closed_at')
            ->whereHas('requestedBy', fn ($query) => $query->where('role', User::ROLE_EMPLOYEE))
            ->whereHas('items', fn ($query) => $query->where('item_id', $distribution->item_id))
            ->latest('created_at')
            ->get()
            ->first(fn (Requisition $requisition): bool => EmployeeRequisitionStatus::remainingToFulfillForItem(
                $requisition,
                (int) $distribution->item_id,
            ) > 0);
    }
}
