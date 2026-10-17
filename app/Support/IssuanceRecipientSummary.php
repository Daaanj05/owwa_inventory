<?php

namespace App\Support;

use App\Models\Issuance;
use App\Models\User;
use Illuminate\Support\Collection;

class IssuanceRecipientSummary
{
    /**
     * @return array{unit_consolidator: string|null, employee: string|null}
     */
    public static function forIssuance(Issuance $issuance): array
    {
        $issuance->loadMissing(['issuedTo']);
        $issuedTo = $issuance->issuedTo;

        return [
            'unit_consolidator' => self::resolveUnitConsolidatorName($issuance),
            'employee' => $issuedTo instanceof User && $issuedTo->isEmployee() ? $issuedTo->name : null,
        ];
    }

    /**
     * SC issues to employees directly on consolidated RIS; UC is the consolidator/endorser.
     */
    public static function resolveUnitConsolidatorName(Issuance $issuance): ?string
    {
        $issuance->loadMissing(['issuedTo', 'requisition.requestedBy', 'consolidatedRequisition.requestedBy']);

        if ($issuance->consolidated_requisition_id) {
            $uc = $issuance->consolidatedRequisition?->requestedBy;
            if ($uc instanceof User && $uc->isUnitConsolidator()) {
                return $uc->name;
            }
        }

        $issuedTo = $issuance->issuedTo;
        if ($issuedTo instanceof User && $issuedTo->isUnitConsolidator()) {
            return $issuedTo->name;
        }

        $requestedBy = $issuance->requisition?->requestedBy;
        if ($requestedBy instanceof User && $requestedBy->isUnitConsolidator()) {
            return $requestedBy->name;
        }

        $ucs = self::unitConsolidatorsForOffice(
            $issuance->office_id ? (int) $issuance->office_id : null,
            $issuance->department_id ? (int) $issuance->department_id : null,
        );

        return $ucs->first()?->name;
    }

    /**
     * Compact holder label for Stock Levels / ledger: employee who received it, with UC as endorser.
     */
    public static function holderLabelForIssuance(Issuance $issuance): ?string
    {
        $summary = self::forIssuance($issuance);
        $employee = $summary['employee'];
        $uc = $summary['unit_consolidator'];

        if ($employee === null) {
            return $uc;
        }

        return filled($uc) ? "{$employee} (via {$uc})" : $employee;
    }

    /**
     * @return Collection<int, User>
     */
    public static function unitConsolidatorsForOffice(?int $officeId, ?int $departmentId = null): Collection
    {
        if ($officeId === null || $officeId <= 0) {
            return collect();
        }

        return RequisitionNotificationRecipients::unitConsolidatorsForOffice($officeId, $departmentId);
    }
}
