<?php

namespace App\Services;

use App\Models\Requisition;
use App\Models\User;
use App\Notifications\RequisitionRejectedMailNotification;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
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
                RequisitionNotificationRecipients::unitConsolidatorsForOffice((int) $requisition->office_id),
                'New employee requisition',
                $this->bodyFor($requisition),
                $requisition,
            );

            return;
        }

        if ($requester->isUnitConsolidator()) {
            $this->notifyUsers(
                RequisitionNotificationRecipients::supplyCustodians(),
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
        $user->notify(new RequisitionRejectedMailNotification($requisition, $title));
    }

    protected function bodyFor(Requisition $requisition, bool $includeRemarks = false): string
    {
        $officeName = $requisition->office?->name ?? 'Office';
        $body = sprintf('%s — %s', $requisition->reference_code ?? 'Requisition', $officeName);

        if ($includeRemarks && filled($requisition->remarks)) {
            $body .= '. Reason: '.$requisition->remarks;
        }

        return $body;
    }
}
