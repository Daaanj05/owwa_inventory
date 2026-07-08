<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class RequisitionNotificationRecipients
{
    /**
     * @return Collection<int, User>
     */
    public static function unitConsolidatorsForOffice(int $officeId, ?int $departmentId = null): Collection
    {
        if ($officeId <= 0) {
            return new Collection;
        }

        $query = User::query()
            ->where('role', User::ROLE_UNIT_CONSOLIDATOR)
            ->where(function ($scoped) use ($officeId, $departmentId): void {
                $scoped->where(function ($legacy) use ($officeId, $departmentId): void {
                    $legacy->where('office_id', $officeId);

                    if ($departmentId !== null && $departmentId > 0) {
                        $legacy->where('department_id', $departmentId);
                    }
                })->orWhereHas('assignments', function ($assignments) use ($officeId, $departmentId): void {
                    $assignments->where('office_id', $officeId);

                    if ($departmentId !== null && $departmentId > 0) {
                        $assignments->where('department_id', $departmentId);
                    }
                });
            });

        return $query->get();
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
