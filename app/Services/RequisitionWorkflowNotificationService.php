<?php

namespace App\Services;

use App\Models\Requisition;
use App\Models\User;
use App\Notifications\RequisitionRejectedMailNotification;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Support\EmployeeRequisitionOriginalSubmission;
use App\Support\MailDelivery;
use App\Support\NotificationRecipientResolver;
use App\Support\RequisitionNotificationRecipients;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class RequisitionWorkflowNotificationService
{
    public function handleCreated(Requisition $requisition): void
    {
        if ($requisition->status !== Requisition::STATUS_PENDING) {
            return;
        }

        $requisition->loadMissing(['requestedBy', 'office']);
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
                'New employee requisition',
                $this->bodyFor($requisition),
                $requisition,
            );

            return;
        }

        if ($requester->isUnitConsolidator()) {
            $this->notifyUsers(
                app(NotificationRecipientResolver::class)->supplyCustodiansForRegionalOffice(),
                'Consolidated requisition submitted',
                $this->bodyFor($requisition),
                $requisition,
            );
        }
    }

    public function handleUpdated(Requisition $requisition, ?string $previousStatus): void
    {
        if ($previousStatus === null || $previousStatus === $requisition->status) {
            return;
        }

        $requisition->loadMissing(['requestedBy', 'office']);
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
                'New employee requisition',
                $this->bodyFor($requisition),
                $requisition,
            );

            return;
        }

        if ($previousStatus === Requisition::STATUS_PENDING
            && $requisition->status === Requisition::STATUS_ACCEPTED
            && $requester->isEmployee()) {
            $this->notifyUser(
                $requester,
                'Requisition approved',
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

    public function handleCustodianIssued(Requisition $requisition): void
    {
        $requisition->loadMissing(['requestedBy', 'office']);
        $requester = $requisition->requestedBy;

        if ($requester === null) {
            return;
        }

        $this->notifyUser(
            $requester,
            'Stock issued for your requisition',
            $this->bodyFor($requisition),
            $requisition,
        );
    }

    public function handleCustodianRejected(Requisition $requisition): void
    {
        $requisition->loadMissing(['requestedBy', 'office']);
        $requester = $requisition->requestedBy;

        if ($requester === null) {
            return;
        }

        $this->notifyUserWithRejectionMail(
            $requester,
            'Requisition rejected',
            $this->bodyFor($requisition, includeRemarks: true),
            $requisition,
        );
    }

    public function handleDistributed(User $employee, Requisition $requisition): void
    {
        $requisition->loadMissing(['office']);

        $this->notifyUser(
            $employee,
            'Items distributed to you',
            $this->bodyFor($requisition),
            $requisition,
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

            if ($endorsed >= $requested) {
                continue;
            }

            $sourceId = (int) ($line['source_requisition_id'] ?? 0);
            $bySource[$sourceId][] = $line;
        }

        if ($bySource === []) {
            return;
        }

        foreach ($bySource as $sourceId => $reductions) {
            $source = Requisition::query()
                ->with('requestedBy')
                ->find($sourceId);

            $employee = $source?->requestedBy;

            if (! $source instanceof Requisition || ! $employee instanceof User || ! $employee->isEmployee()) {
                continue;
            }

            $ref = $source->displayTransactionNumber() ?? "#{$source->id}";
            $detailParts = [];

            foreach ($reductions as $reduction) {
                $itemName = (string) ($reduction['item_name'] ?? 'Item');
                $detailParts[] = sprintf(
                    '%s: requested %d, endorsed %d. Reason: %s',
                    $itemName,
                    (int) ($reduction['requested_quantity'] ?? 0),
                    (int) ($reduction['endorsed_quantity'] ?? 0),
                    (string) ($reduction['employee_remarks'] ?? ''),
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

        $requisition->loadMissing(['requestedBy', 'office']);

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
        );
    }

    public function handleBackorderAcknowledged(Requisition $requisition): void
    {
        $requisition->loadMissing(['requestedBy', 'office', 'sourceRequests.requestedBy']);

        $risNumber = $requisition->reference_code ?? 'RIS';
        $title = 'RIS acknowledged — awaiting stock';
        $body = sprintf('%s — regional stock is not yet available.', $risNumber);

        $requester = $requisition->requestedBy;

        if ($requester instanceof User) {
            $this->notifyUser($requester, $title, $body, $requisition);
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
    protected function notifyUsers(Collection|array $users, string $title, string $body, Requisition $requisition): void
    {
        $users = $users instanceof Collection ? $users : collect($users);

        if ($users->isEmpty()) {
            return;
        }

        Notification::send(
            $users,
            new RequisitionWorkflowDatabaseNotification($title, $body, (int) $requisition->id),
        );
    }

    protected function notifyUser(User $user, string $title, string $body, Requisition $requisition): void
    {
        $user->notify(new RequisitionWorkflowDatabaseNotification($title, $body, (int) $requisition->id));
    }

    protected function notifyUserWithRejectionMail(User $user, string $title, string $body, Requisition $requisition): void
    {
        $user->notify(new RequisitionWorkflowDatabaseNotification($title, $body, (int) $requisition->id));

        MailDelivery::attempt(
            fn (): mixed => $user->notify(new RequisitionRejectedMailNotification($requisition, $title))
        );
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
}
