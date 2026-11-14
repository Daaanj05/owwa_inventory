<?php

namespace App\Services;

use App\Models\Requisition;
use App\Models\User;
use App\Notifications\RequisitionRejectedMailNotification;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Support\EmployeeRequisitionOriginalSubmission;
use App\Support\EmployeeRequisitionStatus;
use App\Support\NotificationRecipientResolver;
use App\Support\RequisitionNotificationRecipients;
use App\Support\WorkflowEmailContext;
use App\Support\WorkflowEmailNotifier;
use Illuminate\Support\Collection;

class RequisitionWorkflowNotificationService
{
    public function handleCreated(Requisition $requisition): void
    {
        $this->hydrateStatusFromDatabase($requisition);

        if ($requisition->status !== Requisition::STATUS_PENDING) {
            return;
        }

        $requisition->loadMissing(['requestedBy', 'office', 'department']);
        $requester = $requisition->requestedBy;

        if ($requester === null) {
            return;
        }

        if ($requester->isEmployee()) {
            $this->notifyUsers(
                RequisitionNotificationRecipients::unitConsolidatorsForOffice(
                    (int) $requisition->office_id,
                    $requisition->department_id ? (int) $requisition->department_id : null,
                ),
                $this->newEmployeeRequisitionTitle($requester),
                sprintf(
                    '%s — awaiting your review.',
                    $requisition->displayTransactionNumber() ?? $requisition->reference_code ?? 'Requisition',
                ),
                $requisition,
                WorkflowEmailContext::fromRequisition($requisition),
            );

            return;
        }

        if ($requester->isUnitConsolidator()) {
            $this->notifyUsers(
                app(NotificationRecipientResolver::class)->supplyCustodiansForRegionalOffice(),
                'Consolidated requisition submitted',
                $this->bodyForConsolidated($requisition),
                $requisition,
            );
        }
    }

    public function handleUpdated(Requisition $requisition, ?string $previousStatus): void
    {
        if ($previousStatus === null || $previousStatus === $requisition->status) {
            return;
        }

        $requisition->loadMissing(['requestedBy', 'office', 'department']);
        $requester = $requisition->requestedBy;

        if ($requester === null) {
            return;
        }

        if ($previousStatus === Requisition::STATUS_DRAFT
            && $requisition->status === Requisition::STATUS_PENDING
            && $requester->isEmployee()) {
            $this->notifyUsers(
                RequisitionNotificationRecipients::unitConsolidatorsForOffice(
                    (int) $requisition->office_id,
                    $requisition->department_id ? (int) $requisition->department_id : null,
                ),
                $this->newEmployeeRequisitionTitle($requester),
                sprintf(
                    '%s — awaiting your review.',
                    $requisition->displayTransactionNumber() ?? $requisition->reference_code ?? 'Requisition',
                ),
                $requisition,
                WorkflowEmailContext::fromRequisition($requisition),
            );

            return;
        }

        if ($previousStatus === Requisition::STATUS_PENDING
            && $requisition->status === Requisition::STATUS_ACCEPTED
            && $requester->isEmployee()) {
            $this->notifyUser(
                $requester,
                'Requisition reviewed',
                $this->bodyFor($requisition),
                $requisition,
            );

            return;
        }

        if ($previousStatus === Requisition::STATUS_PENDING
            && $requisition->status === Requisition::STATUS_REJECTED
            && $requester->isEmployee()) {
            $this->notifyUserWithRejectionMail(
                $requester,
                'Requisition rejected',
                $this->bodyFor($requisition, includeRemarks: true),
                $requisition,
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $preparedLines
     */
    public function handleRisSavedForApproval(Requisition $requisition, array $preparedLines = []): void
    {
        $requisition->loadMissing([
            'requestedBy',
            'office',
            'department',
            'sourceEndorsements.sourceRequisition.requestedBy',
        ]);

        $risNumber = $requisition->reference_code ?? 'RIS';
        $title = 'RIS for approval';
        $body = sprintf('%s — waiting for the approver’s signature before stock is issued.', $risNumber);

        $consolidator = $requisition->requestedBy;
        if ($consolidator instanceof User) {
            $this->notifyUser(
                $consolidator,
                $title,
                $body,
                $requisition,
                $consolidator->isUnitConsolidator()
                    ? WorkflowEmailContext::fromRequisition($requisition)
                    : null,
            );
        }

        $employeeIds = [];
        foreach ($preparedLines as $row) {
            if (! is_array($row) || (int) ($row['quantity_to_issue'] ?? 0) <= 0) {
                continue;
            }

            $endorsementId = (int) ($row['source_endorsement_id'] ?? 0);
            if ($endorsementId <= 0) {
                continue;
            }

            $endorsement = $requisition->sourceEndorsements->firstWhere('id', $endorsementId);
            $employee = $endorsement?->sourceRequisition?->requestedBy;

            if (! $employee instanceof User || ! $employee->isEmployee()) {
                continue;
            }

            if (isset($employeeIds[$employee->id])) {
                continue;
            }

            $employeeIds[$employee->id] = true;
            $source = $endorsement->sourceRequisition;
            $transaction = $source?->displayTransactionNumber() ?? $source?->reference_code ?? 'Requisition';

            $this->notifyUser(
                $employee,
                $title,
                sprintf('%s — %s is waiting for the approver’s signature before stock is issued.', $risNumber, $transaction),
                $source ?? $requisition,
            );
        }
    }

    public function handleCustodianIssued(Requisition $requisition): void
    {
        $requisition->loadMissing([
            'requestedBy',
            'office',
            'department',
            'sourceRequests.requestedBy',
            'sourceRequests.office',
            'sourceRequests.department',
        ]);

        foreach ($requisition->sourceRequests as $sourceRequest) {
            if (! $sourceRequest->isEmployeeRequest()) {
                continue;
            }

            if ($sourceRequest->closed_at !== null) {
                continue;
            }

            $target = EmployeeRequisitionStatus::fulfillmentTargetTotal($sourceRequest);
            $issued = EmployeeRequisitionStatus::issuedTotal($sourceRequest);

            if ($issued <= 0 || $issued >= $target) {
                continue;
            }

            $employee = $sourceRequest->requestedBy;
            $summary = EmployeeRequisitionStatus::formatSummary($issued, $target);
            $transaction = $sourceRequest->displayTransactionNumber() ?? $sourceRequest->reference_code ?? 'Requisition';
            $ris = $requisition->reference_code ?? 'RIS';
            $title = 'Requisition partially issued';
            $employeeNotified = false;

            if ($employee instanceof User && $employee->isEmployee()) {
                $this->notifyUser(
                    $employee,
                    $title,
                    sprintf('%s — %s.', $transaction, $summary),
                    $sourceRequest,
                );
                $employeeNotified = true;
            }

            $uc = $requisition->requestedBy;

            if ($uc instanceof User && (! $employeeNotified || (int) $uc->id !== (int) ($employee?->id ?? 0))) {
                $this->notifyUser(
                    $uc,
                    $title,
                    sprintf('%s — %s partially issued. %s.', $ris, $transaction, $summary),
                    $sourceRequest,
                    WorkflowEmailContext::fromRequisition($sourceRequest),
                );
            }
        }

        if ($requisition->sourceRequests->isNotEmpty()) {
            return;
        }

        $requester = $requisition->requestedBy;

        if (! $requester instanceof User) {
            return;
        }

        if ($requisition->hasRemainingToIssue()) {
            $this->notifyUser(
                $requester,
                'Requisition partially issued',
                $this->bodyFor($requisition),
                $requisition,
                $requester->isUnitConsolidator()
                    ? WorkflowEmailContext::fromRequisition($requisition)
                    : null,
            );
        }
    }

    public function handleCustodianRejected(Requisition $requisition): void
    {
        $requisition->loadMissing(['requestedBy', 'office', 'department', 'sourceRequests.requestedBy', 'sourceRequests.office', 'sourceRequests.department']);
        $requester = $requisition->requestedBy;

        if ($requester === null) {
            return;
        }

        $context = $requester->isUnitConsolidator() && $requisition->sourceRequests->isNotEmpty()
            ? WorkflowEmailContext::fromSourceRecords($requisition->sourceRequests, $requisition->office?->name, $requisition->department?->name)
            : ($requester->isUnitConsolidator() ? WorkflowEmailContext::fromRequisition($requisition) : null);

        $this->notifyUserWithRejectionMail(
            $requester,
            'Requisition rejected',
            $this->bodyFor($requisition, includeRemarks: true),
            $requisition,
            $context,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $endorsementLines
     */
    public function handleEndorsedWithAdjustments(Requisition $consolidated, array $endorsementLines): void
    {
        if ($endorsementLines === []) {
            return;
        }

        /** @var array<int, array<int, array<string, mixed>>> $bySource */
        $bySource = [];

        foreach ($endorsementLines as $line) {
            $requested = (int) ($line['requested_quantity'] ?? 0);
            $endorsed = (int) ($line['endorsed_quantity'] ?? 0);

            if ($endorsed === $requested) {
                continue;
            }

            $sourceId = (int) ($line['source_requisition_id'] ?? 0);
            $bySource[$sourceId][] = $line;
        }

        if ($bySource === []) {
            return;
        }

        foreach ($bySource as $sourceId => $adjustments) {
            $source = Requisition::query()
                ->with('requestedBy')
                ->find($sourceId);

            $employee = $source?->requestedBy;

            if (! $source instanceof Requisition || ! $employee instanceof User || ! $employee->isEmployee()) {
                continue;
            }

            $ref = $source->displayTransactionNumber() ?? "#{$source->id}";
            $detailParts = [];

            foreach ($adjustments as $adjustment) {
                $itemName = (string) ($adjustment['item_name'] ?? 'Item');
                $detailParts[] = sprintf(
                    '%s: requested %d, endorsed %d. Reason: %s',
                    $itemName,
                    (int) ($adjustment['requested_quantity'] ?? 0),
                    (int) ($adjustment['endorsed_quantity'] ?? 0),
                    (string) ($adjustment['employee_remarks'] ?? ''),
                );
            }

            $body = sprintf(
                'Your requisition %s was endorsed to Supply Custodian. %s',
                $ref,
                implode(' ', $detailParts),
            );

            $this->notifyUser($employee, 'Requisition endorsed with adjustments', $body, $source);
        }
    }

    public function handleEmployeeContentEdited(Requisition $requisition): void
    {
        if (! $requisition->isEmployeeRequest() || ! $requisition->isPendingCustodianReview()) {
            return;
        }

        if (! EmployeeRequisitionOriginalSubmission::differsFromCurrent($requisition)) {
            return;
        }

        $requisition->loadMissing(['requestedBy', 'office', 'department']);

        $this->notifyUsers(
            RequisitionNotificationRecipients::unitConsolidatorsForOffice(
                (int) $requisition->office_id,
                $requisition->department_id ? (int) $requisition->department_id : null,
            ),
            'Employee updated a pending requisition',
            sprintf(
                '%s — %s edited quantities or purpose after submitting.',
                $requisition->displayTransactionNumber() ?? $requisition->reference_code ?? 'Requisition',
                $requisition->requestedBy?->name ?? 'Employee',
            ),
            $requisition,
            WorkflowEmailContext::fromRequisition($requisition),
        );
    }

    public function handleBackorderAcknowledged(Requisition $requisition): void
    {
        $requisition->loadMissing([
            'requestedBy',
            'office',
            'department',
            'sourceRequests.requestedBy',
            'sourceRequests.office',
            'sourceRequests.department',
        ]);

        $risNumber = $requisition->reference_code ?? 'RIS';
        $title = 'RIS acknowledged — awaiting stock';
        $body = sprintf('%s — regional stock is not yet available.', $risNumber);

        $requester = $requisition->requestedBy;

        if ($requester instanceof User) {
            $context = $requisition->sourceRequests->isNotEmpty()
                ? WorkflowEmailContext::fromSourceRecords(
                    $requisition->sourceRequests,
                    $requisition->office?->name,
                    $requisition->department?->name,
                )
                : ($requester->isUnitConsolidator() ? WorkflowEmailContext::fromRequisition($requisition) : null);

            $this->notifyUser($requester, $title, $body, $requisition, $context);
        }

        foreach ($requisition->sourceRequests as $sourceRequest) {
            $employee = $sourceRequest->requestedBy;

            if ($employee instanceof User && $employee->isEmployee()) {
                $this->notifyUser(
                    $employee,
                    $title,
                    sprintf('%s — your request is queued until stock arrives.', $sourceRequest->displayTransactionNumber() ?? 'Requisition'),
                    $sourceRequest,
                );
            }
        }
    }

    /**
     * @param  Collection<int, User>|array<int, User>  $users
     */
    protected function notifyUsers(
        Collection|array $users,
        string $title,
        string $body,
        Requisition $requisition,
        ?WorkflowEmailContext $context = null,
    ): void {
        $users = $users instanceof Collection ? $users : collect($users);

        foreach ($users as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $this->notifyUser($user, $title, $body, $requisition, $context);
        }
    }

    protected function notifyUser(
        User $user,
        string $title,
        string $body,
        Requisition $requisition,
        ?WorkflowEmailContext $context = null,
    ): void {
        $user->notify(new RequisitionWorkflowDatabaseNotification($title, $body, (int) $requisition->id));

        $mailContext = $context;
        if ($mailContext === null && $user->isUnitConsolidator()) {
            $mailContext = WorkflowEmailContext::fromRequisition($requisition);
        }

        app(WorkflowEmailNotifier::class)->queue(
            $user,
            $title,
            $body,
            ['requisition_id' => (int) $requisition->id],
            $user->isUnitConsolidator() ? $mailContext : null,
        );
    }

    protected function notifyUserWithRejectionMail(
        User $user,
        string $title,
        string $body,
        Requisition $requisition,
        ?WorkflowEmailContext $context = null,
    ): void {
        $user->notify(new RequisitionWorkflowDatabaseNotification($title, $body, (int) $requisition->id));

        $mailContext = $user->isUnitConsolidator()
            ? ($context ?? WorkflowEmailContext::fromRequisition($requisition))
            : null;

        $mail = new RequisitionRejectedMailNotification(
            $requisition,
            $title,
            context: $mailContext,
        );

        app(WorkflowEmailNotifier::class)->queue(
            $user,
            $title,
            $body,
            ['requisition_id' => (int) $requisition->id],
            $mailContext,
            $mail,
        );
    }

    /**
     * A create that omits status still stores the column default. The in-memory
     * model stays blank until it is read back, which skipped the custodian bell.
     */
    protected function hydrateStatusFromDatabase(Requisition $requisition): void
    {
        if (filled($requisition->status) || ! $requisition->exists) {
            return;
        }

        $status = $requisition->newQuery()->whereKey($requisition->getKey())->value('status');

        if (is_string($status) && $status !== '') {
            $requisition->status = $status;
        }
    }

    protected function newEmployeeRequisitionTitle(User $requester): string
    {
        $name = trim((string) $requester->name);

        if ($name === '') {
            return 'New employee requisition';
        }

        return sprintf('New requisition from %s', $name);
    }

    protected function bodyFor(Requisition $requisition, bool $includeRemarks = false): string
    {
        $officeName = $requisition->office?->name ?? 'Office';
        $body = sprintf('%s — %s', $requisition->displayTransactionNumber() ?? $requisition->reference_code ?? 'Requisition', $officeName);

        if ($includeRemarks && filled($requisition->remarks)) {
            $body .= '. Reason: '.$requisition->remarks;
        }

        return $body;
    }

    protected function bodyForConsolidated(Requisition $requisition): string
    {
        $officeName = $requisition->office?->name ?? 'Office';
        $departmentName = $requisition->department?->name;

        $location = filled($departmentName)
            ? "{$officeName} / {$departmentName}"
            : $officeName;

        return sprintf(
            '%s — %s',
            $requisition->reference_code ?? $requisition->displayTransactionNumber() ?? 'RIS',
            $location,
        );
    }
}
