<?php

namespace App\Services;

use App\Models\Distribution;
use App\Models\Requisition;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Support\EmployeeRequisitionStatus;

class EmployeeRequisitionClosureService
{
    public function closeFromDistribution(Distribution $distribution): void
    {
        $distribution->loadMissing(['requisition.requestedBy', 'distributedTo']);

        $requisition = $this->resolveEmployeeRequisition($distribution);

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

        $requisition->update([
            'closed_at' => now(),
            'fulfillment_summary' => EmployeeRequisitionStatus::formatSummary($distributed, $target),
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

    protected function resolveEmployeeRequisition(Distribution $distribution): ?Requisition
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
