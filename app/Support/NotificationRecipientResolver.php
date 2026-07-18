<?php

namespace App\Support;

use App\Models\Issuance;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class NotificationRecipientResolver
{
    public function __construct(
        protected SupplyOfficeResolver $supplyOfficeResolver,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function supplyCustodiansForRegionalOffice(): Collection
    {
        $regionalOffice = $this->supplyOfficeResolver->resolveOffice();

        if ($regionalOffice instanceof Office) {
            return $this->supplyCustodiansForOffice((int) $regionalOffice->id);
        }

        return RequisitionNotificationRecipients::supplyCustodians();
    }

    /**
     * @return Collection<int, User>
     */
    public function supplyCustodiansForOffice(int $officeId): Collection
    {
        if ($officeId <= 0) {
            return new Collection;
        }

        return User::query()
            ->where('role', User::ROLE_SUPPLY_CUSTODIAN)
            ->where('office_id', $officeId)
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function unitConsolidatorsForRequisition(Requisition $requisition): Collection
    {
        return RequisitionNotificationRecipients::unitConsolidatorsForOffice(
            (int) $requisition->office_id,
            $requisition->department_id ? (int) $requisition->department_id : null,
        );
    }

    /**
     * @return Collection<int, User>
     */
    public function eulReminderRecipients(Issuance $issuance): Collection
    {
        $issuance->loadMissing(['issuedTo', 'office', 'department']);

        $recipients = $this->supplyCustodiansForRegionalOffice();

        if ($issuance->office_id) {
            $officeCustodians = $this->supplyCustodiansForOffice((int) $issuance->office_id);
            $recipients = $recipients->merge($officeCustodians);

            $unitConsolidators = IssuanceDistributionVisibility::unitConsolidatorsForOffice(
                (int) $issuance->office_id,
                $issuance->department_id ? (int) $issuance->department_id : null,
            );
            $recipients = $recipients->merge($unitConsolidators);
        }

        if ($issuance->issued_to) {
            $issuedTo = $issuance->issuedTo;

            if ($issuedTo instanceof User) {
                $recipients->push($issuedTo);
            }
        }

        return $recipients->unique('id')->values();
    }
}
