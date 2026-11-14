<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\Requisition;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Support\EmployeeRequisitionStatus;
use App\Support\WorkflowEmailContext;
use App\Support\WorkflowEmailNotifier;
use InvalidArgumentException;

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

    /**
     * Supply Custodian short-closes remaining balances on linked employee requests.
     *
     * @return array{closed: int}
     */
    public function closeRemainingBalanceByCustodian(Requisition $consolidated, User $custodian): array
    {
        if (! $custodian->isSupplyCustodian()) {
            throw new InvalidArgumentException('Only a Supply Custodian can close the remaining balance.');
        }

        $consolidated->loadMissing([
            'sourceRequests.requestedBy',
            'sourceRequests.office',
            'sourceRequests.department',
            'sourceRequests.compiledIntoRequisition.requestedBy',
        ]);

        $closed = 0;

        $sources = $consolidated->sourceRequests->isNotEmpty()
            ? $consolidated->sourceRequests
            : collect($consolidated->isEmployeeRequest() ? [$consolidated] : []);

        foreach ($sources as $source) {
            if (! $source instanceof Requisition || ! $source->isEmployeeRequest()) {
                continue;
            }

            if ($source->closed_at !== null) {
                continue;
            }

            $target = EmployeeRequisitionStatus::fulfillmentTargetTotal($source);
            $issued = EmployeeRequisitionStatus::issuedTotal($source);

            if ($target <= 0 || $issued >= $target) {
                continue;
            }

            $summary = EmployeeRequisitionStatus::formatSummary($issued, $target);

            $source->update([
                'closed_at' => now(),
                'fulfillment_summary' => $summary,
            ]);

            $reference = $source->displayTransactionNumber() ?? 'your request';
            $body = sprintf(
                '%s was closed by the Supply Custodian. %s. The remaining quantity will not be issued.',
                $reference,
                $summary,
            );

            $this->notifyClosureRecipients($source, $body);
            $closed++;
        }

        return ['closed' => $closed];
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

        $reference = $requisition->displayTransactionNumber() ?? 'your request';
        $body = sprintf(
            '%s is closed. %s. File a new requisition for any remaining need.',
            $reference,
            $requisition->fulfillment_summary,
        );

        $this->notifyClosureRecipients($requisition, $body);
    }

    protected function notifyClosureRecipients(Requisition $requisition, string $body): void
    {
        $requisition->loadMissing([
            'requestedBy',
            'office',
            'department',
            'compiledIntoRequisition.requestedBy',
        ]);

        $title = 'Requisition closed';
        $notified = [];

        $employee = $requisition->requestedBy;

        if ($employee instanceof User) {
            $this->notifyUser($employee, $title, $body, $requisition);
            $notified[(int) $employee->id] = true;
        }

        $uc = $requisition->compiledIntoRequisition?->requestedBy;

        if ($uc instanceof User && ! isset($notified[(int) $uc->id])) {
            $this->notifyUser(
                $uc,
                $title,
                $body,
                $requisition,
                WorkflowEmailContext::fromRequisition($requisition),
            );
        }
    }

    protected function notifyUser(
        User $user,
        string $title,
        string $body,
        Requisition $requisition,
        ?WorkflowEmailContext $context = null,
    ): void {
        $user->notify(new RequisitionWorkflowDatabaseNotification(
            $title,
            $body,
            (int) $requisition->id,
        ));

        app(WorkflowEmailNotifier::class)->queue(
            $user,
            $title,
            $body,
            ['requisition_id' => (int) $requisition->id],
            $user->isUnitConsolidator()
                ? ($context ?? WorkflowEmailContext::fromRequisition($requisition))
                : null,
        );
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
