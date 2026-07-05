<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\RequisitionFulfillmentService;
use Illuminate\Support\Carbon;

/**
 * Demo seeding helper — mirrors the production custody chain:
 *
 * Item → Acquisition → Requisition (RIS No.) → Issuance (Serial/PAR/ICS No.) → Transfer / Disposal
 *
 * @see OwwaReferenceLabels RIS No. maps to requisition.reference_code, not issuance.reference_code.
 */
class DemoInventoryWorkflow
{
    public function __construct(
        protected RequisitionFulfillmentService $fulfillment,
    ) {}

    /**
     * @param  array<int, array{item: Item, quantity: int}>  $lines
     */
    public function seedRequisition(
        string $referenceCode,
        Office $office,
        Department $department,
        User $requestedBy,
        array $lines,
        string $status = Requisition::STATUS_ACCEPTED,
        ?User $approvedBy = null,
        ?Carbon $approvedAt = null,
        ?string $remarks = null,
    ): Requisition {
        $requisition = Requisition::updateOrCreate(
            ['reference_code' => $referenceCode],
            [
                'office_id' => $office->id,
                'department_id' => $department->id,
                'requested_by' => $requestedBy->id,
                'status' => $status,
                'remarks' => $remarks,
                'approved_by' => $approvedBy?->id,
                'approved_at' => $approvedAt,
            ],
        );

        foreach ($lines as $line) {
            RequisitionItem::updateOrCreate(
                [
                    'requisition_id' => $requisition->id,
                    'item_id' => $line['item']->id,
                ],
                [
                    'quantity' => $line['quantity'],
                ],
            );
        }

        return $requisition->fresh(['items.item']);
    }

    /**
     * Issue all remaining lines on an accepted requisition (runs IssuanceObserver).
     *
     * @return int Number of issuances created
     */
    public function issueAllLines(
        Requisition $requisition,
        User $custodian,
        string $issuanceDate,
    ): int {
        if ($requisition->status !== Requisition::STATUS_ACCEPTED) {
            return 0;
        }

        $requisition->load('items');

        $rows = [];

        foreach ($requisition->items as $line) {
            $remaining = $this->fulfillment->remainingQuantity($line);

            if ($remaining <= 0) {
                continue;
            }

            $rows[] = [
                'requisition_item_id' => $line->id,
                'quantity_to_issue' => $remaining,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        $previousUser = auth()->user();
        auth()->login($custodian);

        try {
            $result = $this->fulfillment->issueLines(
                $requisition->fresh(['items']),
                $custodian,
                $rows,
                $issuanceDate,
            );
        } finally {
            if ($previousUser !== null) {
                auth()->login($previousUser);
            } else {
                auth()->logout();
            }
        }

        return $result['created'];
    }

    /**
     * Seed issuances grouped by date and department — one requisition (RIS) per group.
     *
     * @param  array<int, array{item: string, qty: int, date: string, dept: Department}>  $issuanceRows
     * @param  array<string, Item>  $itemMap
     */
    public function seedIssuanceBatchesFromGroups(
        array $issuanceRows,
        array $itemMap,
        Office $office,
        User $requestedBy,
        User $custodian,
        User $approvedBy,
        int &$requisitionSeq,
        string $referencePrefix = 'REQ-DEMO-',
    ): int {
        $groups = [];

        foreach ($issuanceRows as $row) {
            $key = $row['date'].'|'.$row['dept']->id;
            $groups[$key][] = $row;
        }

        $issuanceCount = 0;

        foreach ($groups as $groupRows) {
            $first = $groupRows[0];
            $lines = [];

            foreach ($groupRows as $row) {
                $item = $itemMap[$row['item']] ?? null;

                if ($item === null) {
                    continue;
                }

                $lines[] = [
                    'item' => $item,
                    'quantity' => $row['qty'],
                ];
            }

            if ($lines === []) {
                continue;
            }

            $referenceCode = $referencePrefix.str_pad((string) $requisitionSeq++, 4, '0', STR_PAD_LEFT);

            $requisition = $this->seedRequisition(
                referenceCode: $referenceCode,
                office: $office,
                department: $first['dept'],
                requestedBy: $requestedBy,
                lines: $lines,
                approvedBy: $approvedBy,
                approvedAt: Carbon::parse($first['date'])->subDay(),
            );

            $issuanceCount += $this->issueAllLines(
                $requisition,
                $custodian,
                $first['date'],
            );
        }

        return $issuanceCount;
    }

    /**
     * @return array<int, string>
     */
    public static function phaseOrder(): array
    {
        return [
            'items',
            'acquisitions',
            'units',
            'requisitions',
            'issuances',
            'distributions',
            'transfers',
            'disposals',
        ];
    }

    public static function risLabel(): string
    {
        return OwwaReferenceLabels::RIS;
    }

    public static function issuanceControlLabel(?string $categorySlug): string
    {
        return OwwaReferenceLabels::issuanceControl($categorySlug);
    }

    public function countIssuancesMissingRequisition(): int
    {
        return Issuance::query()->whereNull('requisition_id')->count();
    }
}
