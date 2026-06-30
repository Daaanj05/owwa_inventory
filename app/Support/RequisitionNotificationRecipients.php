<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class RequisitionNotificationRecipients
{
    /**
     * @return Collection<int, User>
     */
    public static function unitConsolidatorsForOffice(int $officeId): Collection
    {
        if ($officeId <= 0) {
            return new Collection;
        }

        return User::query()
            ->where('role', User::ROLE_UNIT_CONSOLIDATOR)
            ->where('office_id', $officeId)
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    public static function supplyCustodians(): Collection
    {
        return User::query()
            ->where('role', User::ROLE_SUPPLY_CUSTODIAN)
            ->get();
    }
}
