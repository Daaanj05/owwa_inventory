<?php

namespace App\Support;

use App\Models\Distribution;
use App\Models\Issuance;
use App\Models\User;
use Illuminate\Support\Collection;

class IssuanceDistributionVisibility
{
    public const STATUS_PENDING = 'pending_distribution';

    public const STATUS_PARTIAL = 'partially_distributed';

    public const STATUS_DISTRIBUTED = 'distributed';

    /**
     * @return array{
     *     unit_consolidator: string|null,
     *     distribution_status: string,
     *     distribution_status_label: string,
     *     issued_quantity: int,
     *     distributed_quantity: int,
     *     employees: list<array{name: string, quantity: int, date: string|null}>
     * }
     */
    public static function forIssuance(Issuance $issuance): array
    {
        $issuance->loadMissing(['issuedTo', 'requisition.requestedBy', 'item', 'batch.lines']);
        $lines = $issuance->batchLines();
        $issuedQuantity = (int) $lines->sum('quantity');
        $unitConsolidator = self::resolveUnitConsolidatorName($issuance);

        $distributions = self::distributionsForIssuance($issuance);
        $distributedQuantity = (int) $distributions->sum('quantity');
        $employees = self::employeeRows($distributions);
        $status = self::resolveStatus($issuedQuantity, $distributedQuantity);

        return [
            'unit_consolidator' => $unitConsolidator,
            'distribution_status' => $status,
            'distribution_status_label' => self::statusLabel($status),
            'issued_quantity' => $issuedQuantity,
            'distributed_quantity' => $distributedQuantity,
            'employees' => $employees,
        ];
    }

    /**
     * SC issues to the Unit Consolidator; UC then distributes to employees.
     * Never treat Supply Custodian (or other non-UC roles) as the UC label.
     */
    public static function resolveUnitConsolidatorName(Issuance $issuance): ?string
    {
        $issuance->loadMissing(['issuedTo', 'requisition.requestedBy']);

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
     * Compact holder label for Stock Levels / ledger: employee when distributed, else UC.
     */
    public static function holderLabelForIssuance(Issuance $issuance): ?string
    {
        $summary = self::forIssuance($issuance);
        $employees = $summary['employees'];

        if ($employees === []) {
            return $summary['unit_consolidator'];
        }

        if (count($employees) === 1) {
            $name = $employees[0]['name'];
            $uc = $summary['unit_consolidator'];

            return filled($uc) ? "{$name} (via {$uc})" : $name;
        }

        $names = collect($employees)->pluck('name')->filter()->unique()->values();
        $uc = $summary['unit_consolidator'];
        $list = $names->take(2)->implode(', ');
        if ($names->count() > 2) {
            $list .= ' +'.($names->count() - 2);
        }

        return filled($uc) ? "{$list} (via {$uc})" : $list;
    }

    /**
     * @return Collection<int, Distribution>
     */
    public static function distributionsForIssuance(Issuance $issuance): Collection
    {
        $requisitionIds = $issuance->batchLines()
            ->map(fn (Issuance $line): ?int => $line->requisition_id)
            ->filter()
            ->unique()
            ->values();

        if ($issuance->requisition_id) {
            $requisitionIds = $requisitionIds->push((int) $issuance->requisition_id)->unique()->values();
        }

        $itemIds = $issuance->batchLines()
            ->map(fn (Issuance $line): ?int => $line->item_id)
            ->filter()
            ->unique()
            ->values();

        if ($requisitionIds->isEmpty() || $itemIds->isEmpty()) {
            return collect();
        }

        return Distribution::query()
            ->with(['distributedTo'])
            ->whereIn('requisition_id', $requisitionIds->all())
            ->whereIn('item_id', $itemIds->all())
            ->orderBy('distribution_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Distribution>  $distributions
     * @return list<array{name: string, quantity: int, date: string|null}>
     */
    protected static function employeeRows(Collection $distributions): array
    {
        return $distributions
            ->groupBy(fn (Distribution $distribution): string => (string) ($distribution->distributed_to ?? '0'))
            ->map(function (Collection $group): array {
                /** @var Distribution $first */
                $first = $group->first();
                $name = $first->distributedTo?->name ?? '—';
                $latestDate = $group
                    ->map(fn (Distribution $row): ?string => $row->distribution_date?->format('M j, Y'))
                    ->filter()
                    ->last();

                return [
                    'name' => $name,
                    'quantity' => (int) $group->sum('quantity'),
                    'date' => $latestDate,
                ];
            })
            ->values()
            ->all();
    }

    protected static function resolveStatus(int $issuedQuantity, int $distributedQuantity): string
    {
        if ($distributedQuantity <= 0) {
            return self::STATUS_PENDING;
        }

        if ($issuedQuantity > 0 && $distributedQuantity >= $issuedQuantity) {
            return self::STATUS_DISTRIBUTED;
        }

        return self::STATUS_PARTIAL;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DISTRIBUTED => 'Distributed',
            self::STATUS_PARTIAL => 'Partially distributed',
            default => 'Pending distribution',
        };
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
