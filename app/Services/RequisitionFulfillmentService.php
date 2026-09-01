<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\IssuanceBatch;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\RequisitionSourceEndorsement;
use App\Models\User;
use App\Support\SemiExpendableUsefulLife;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RequisitionFulfillmentService
{
    public function __construct(
        protected InventoryStockService $stockService,
    ) {}

    public function hasSourceEndorsements(Requisition $requisition): bool
    {
        if ($requisition->relationLoaded('sourceEndorsements')) {
            return $requisition->sourceEndorsements->isNotEmpty();
        }

        return $requisition->sourceEndorsements()->exists();
    }

    public function remainingQuantity(RequisitionItem $line): int
    {
        $issued = (int) ($line->quantity_issued ?? 0);

        return max(0, (int) $line->quantity - $issued);
    }

    public function endorsementIssuedQuantity(RequisitionSourceEndorsement $endorsement): int
    {
        return (int) Issuance::query()
            ->where('source_endorsement_id', $endorsement->id)
            ->sum('quantity');
    }

    public function endorsementRemainingQuantity(RequisitionSourceEndorsement $endorsement): int
    {
        return max(0, (int) $endorsement->endorsed_quantity - $this->endorsementIssuedQuantity($endorsement));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function defaultEndorsementIssueLines(Requisition $requisition, bool $remainderOnly = false): array
    {
        $requisition->loadMissing([
            'sourceEndorsements.sourceRequisition.requestedBy',
            'sourceEndorsements.item.category',
            'sourceEndorsements.requisitionItem',
        ]);

        $officeId = (int) $requisition->office_id;

        return $requisition->sourceEndorsements
            ->filter(function (RequisitionSourceEndorsement $endorsement) use ($remainderOnly): bool {
                $remaining = $this->endorsementRemainingQuantity($endorsement);

                if ($remainderOnly) {
                    return $remaining > 0;
                }

                return $remaining > 0 || $this->endorsementIssuedQuantity($endorsement) === 0;
            })
            ->map(function (RequisitionSourceEndorsement $endorsement) use ($officeId): array {
                $remaining = $this->endorsementRemainingQuantity($endorsement);
                $issued = $this->endorsementIssuedQuantity($endorsement);
                $itemId = (int) $endorsement->item_id;
                $stock = $officeId > 0
                    ? max(0, $this->stockService->getStock($itemId, $officeId))
                    : 0;
                $employee = $endorsement->sourceRequisition?->requestedBy;
                $transactionNumber = $endorsement->sourceRequisition?->transaction_number
                    ?? ('#'.$endorsement->source_requisition_id);

                return [
                    'source_endorsement_id' => $endorsement->id,
                    'employee_name' => $employee?->name ?? 'Employee',
                    'transaction_number' => $transactionNumber,
                    'category_label' => $endorsement->item?->category?->name ?? '—',
                    'item_label' => $endorsement->item?->name ?? "Item #{$itemId}",
                    'identifier_value' => $endorsement->requisitionItem?->identifier_value ?? '—',
                    'quantity_requested' => (int) $endorsement->requested_quantity,
                    'quantity_endorsed' => (int) $endorsement->endorsed_quantity,
                    'quantity_issued' => $issued,
                    'quantity_remaining' => $remaining,
                    'stock_available' => $stock,
                    'quantity_to_issue' => min($remaining, $stock),
                    'issue_remarks' => $endorsement->requisitionItem?->issue_remarks ?? '',
                ];
            })
            ->values()
            ->all();
    }

    public function resolveStatusAfterIssue(Requisition $requisition): string
    {
        $requisition->load('items');

        foreach ($requisition->items as $line) {
            if ((int) ($line->quantity_issued ?? 0) > 0) {
                return Requisition::STATUS_ACCEPTED;
            }
        }

        return $requisition->status;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $signatories
     * @return array{created: int, acknowledged: int, categories: array<string, int>}
     */
    public function issueLines(
        Requisition $requisition,
        User $custodian,
        array $rows,
        string $issuanceDate,
        array $signatories = [],
    ): array {
        if (blank($requisition->office_id)) {
            throw new InvalidArgumentException('Requisition must have an office before issuing stock.');
        }

        if ($this->hasSourceEndorsements($requisition)) {
            return $this->issueEndorsementLines($requisition, $custodian, $rows, $issuanceDate, $signatories);
        }

        return $this->issueConsolidatedLines($requisition, $custodian, $rows, $issuanceDate, $signatories);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $signatories
     * @return array{created: int, acknowledged: int, categories: array<string, int>}
     */
    protected function issueConsolidatedLines(
        Requisition $requisition,
        User $custodian,
        array $rows,
        string $issuanceDate,
        array $signatories = [],
    ): array {
        $created = 0;
        $acknowledged = 0;

        /** @var array<string, int> $categoryCounts */
        $categoryCounts = [];

        DB::transaction(function () use ($requisition, $custodian, $rows, $issuanceDate, $signatories, &$created, &$acknowledged, &$categoryCounts): void {
            /** @var Collection<int, array{line: RequisitionItem, qty: int, remarks: ?string}> $issueQueue */
            $issueQueue = collect();

            foreach ($rows as $row) {
                $lineId = (int) ($row['requisition_item_id'] ?? 0);
                $qtyToIssue = (int) ($row['quantity_to_issue'] ?? 0);

                if ($lineId <= 0) {
                    continue;
                }

                /** @var RequisitionItem|null $line */
                $line = RequisitionItem::query()
                    ->where('requisition_id', $requisition->id)
                    ->whereKey($lineId)
                    ->with('item.category')
                    ->first();

                if (! $line) {
                    continue;
                }

                $stock = max(0, $this->stockService->getStock((int) $line->item_id, (int) $requisition->office_id));

                if ($qtyToIssue <= 0) {
                    if (blank($row['issue_remarks'] ?? null)) {
                        continue;
                    }

                    $line->update([
                        'quantity_issued' => (int) ($line->quantity_issued ?? 0),
                        'stock_available' => $stock,
                        'issue_remarks' => (string) $row['issue_remarks'],
                    ]);

                    $acknowledged++;

                    continue;
                }

                $remaining = $this->remainingQuantity($line);
                $requested = (int) $line->quantity;

                if ($qtyToIssue > $remaining) {
                    throw new InvalidArgumentException(
                        "Quantity to issue ({$qtyToIssue}) exceeds remaining requested quantity ({$remaining})."
                    );
                }

                if ($qtyToIssue > $requested) {
                    throw new InvalidArgumentException(
                        "Quantity to issue ({$qtyToIssue}) exceeds requested quantity ({$requested})."
                    );
                }

                $qtyToIssue = min($qtyToIssue, $stock);

                if ($qtyToIssue <= 0) {
                    continue;
                }

                $issueQueue->push([
                    'line' => $line,
                    'qty' => $qtyToIssue,
                    'remarks' => filled($row['issue_remarks'] ?? null) ? (string) $row['issue_remarks'] : null,
                ]);
            }

            $issueQueue
                ->groupBy(fn (array $entry): string => $entry['line']->item?->category?->getTemplateSlug() ?? 'consumables')
                ->each(function (Collection $group, string $categorySlug) use ($requisition, $custodian, $issuanceDate, $signatories, &$created, &$categoryCounts): void {
                    if ($group->isEmpty()) {
                        return;
                    }

                    $batch = $this->createIssuanceBatch(
                        $requisition,
                        $custodian,
                        $categorySlug,
                        $issuanceDate,
                        (int) $requisition->requested_by,
                        $signatories,
                    );

                    foreach ($group as $entry) {
                        /** @var RequisitionItem $line */
                        $line = $entry['line'];
                        $qtyToIssue = $entry['qty'];
                        $stock = max(0, $this->stockService->getStock((int) $line->item_id, (int) $requisition->office_id));

                        $this->createIssuanceRecord(
                            batch: $batch,
                            requisition: $requisition,
                            line: $line,
                            qtyToIssue: $qtyToIssue,
                            issuanceDate: $issuanceDate,
                            custodian: $custodian,
                            issuedToUserId: (int) $requisition->requested_by,
                            signatories: $signatories,
                            stock: $stock,
                            remarks: $entry['remarks'],
                        );

                        $categoryName = $line->item?->category?->name ?? 'Other';
                        $categoryCounts[$categoryName] = ($categoryCounts[$categoryName] ?? 0) + 1;
                        $created++;
                    }
                });

            if ($created > 0) {
                $requisition->refresh();
                $requisition->load('items');

                $requisition->update([
                    'approved_by' => $custodian->id,
                    'approved_at' => now(),
                    'status' => $this->resolveStatusAfterIssue($requisition),
                ]);
            }
        });

        return $this->finalizeIssueResult($requisition, $custodian, $created, $acknowledged, $categoryCounts);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $signatories
     * @return array{created: int, acknowledged: int, categories: array<string, int>}
     */
    protected function issueEndorsementLines(
        Requisition $consolidated,
        User $custodian,
        array $rows,
        string $issuanceDate,
        array $signatories = [],
    ): array {
        $created = 0;
        $acknowledged = 0;

        /** @var array<string, int> $categoryCounts */
        $categoryCounts = [];

        /** @var array<int, true> $employeeRequisitionsToClose */
        $employeeRequisitionsToClose = [];

        DB::transaction(function () use ($consolidated, $custodian, $rows, $issuanceDate, $signatories, &$created, &$acknowledged, &$categoryCounts, &$employeeRequisitionsToClose): void {
            /** @var Collection<int, array{endorsement: RequisitionSourceEndorsement, qty: int, remarks: ?string}> $issueQueue */
            $issueQueue = collect();

            foreach ($rows as $row) {
                $endorsementId = (int) ($row['source_endorsement_id'] ?? 0);
                $qtyToIssue = (int) ($row['quantity_to_issue'] ?? 0);

                if ($endorsementId <= 0) {
                    continue;
                }

                /** @var RequisitionSourceEndorsement|null $endorsement */
                $endorsement = RequisitionSourceEndorsement::query()
                    ->where('consolidated_requisition_id', $consolidated->id)
                    ->whereKey($endorsementId)
                    ->with(['item.category', 'requisitionItem', 'sourceRequisition'])
                    ->first();

                if (! $endorsement) {
                    continue;
                }

                $stock = max(0, $this->stockService->getStock((int) $endorsement->item_id, (int) $consolidated->office_id));

                if ($qtyToIssue <= 0) {
                    if (blank($row['issue_remarks'] ?? null)) {
                        continue;
                    }

                    $endorsement->requisitionItem?->update([
                        'issue_remarks' => (string) $row['issue_remarks'],
                    ]);

                    $acknowledged++;

                    continue;
                }

                $remaining = $this->endorsementRemainingQuantity($endorsement);
                $endorsed = (int) $endorsement->endorsed_quantity;

                if ($qtyToIssue > $remaining) {
                    throw new InvalidArgumentException(
                        "Quantity to issue ({$qtyToIssue}) exceeds remaining endorsed quantity ({$remaining})."
                    );
                }

                if ($qtyToIssue > $endorsed) {
                    throw new InvalidArgumentException(
                        "Quantity to issue ({$qtyToIssue}) exceeds endorsed quantity ({$endorsed})."
                    );
                }

                $qtyToIssue = min($qtyToIssue, $stock);

                if ($qtyToIssue <= 0) {
                    continue;
                }

                $issueQueue->push([
                    'endorsement' => $endorsement,
                    'qty' => $qtyToIssue,
                    'remarks' => filled($row['issue_remarks'] ?? null) ? (string) $row['issue_remarks'] : null,
                ]);
            }

            $issueQueue
                ->groupBy(function (array $entry): string {
                    $endorsement = $entry['endorsement'];
                    $categorySlug = $endorsement->item?->category?->getTemplateSlug() ?? 'consumables';
                    $employeeId = (int) $endorsement->requested_by_user_id;

                    return "{$employeeId}|{$categorySlug}";
                })
                ->each(function (Collection $group) use ($consolidated, $custodian, $issuanceDate, $signatories, &$created, &$categoryCounts, &$employeeRequisitionsToClose): void {
                    if ($group->isEmpty()) {
                        return;
                    }

                    $firstEndorsement = $group->first()['endorsement'];
                    $categorySlug = $firstEndorsement->item?->category?->getTemplateSlug() ?? 'consumables';
                    $employeeId = (int) $firstEndorsement->requested_by_user_id;

                    $batch = $this->createIssuanceBatch(
                        $consolidated,
                        $custodian,
                        $categorySlug,
                        $issuanceDate,
                        (int) $consolidated->requested_by,
                        $signatories,
                    );

                    foreach ($group as $entry) {
                        /** @var RequisitionSourceEndorsement $endorsement */
                        $endorsement = $entry['endorsement'];
                        $qtyToIssue = $entry['qty'];
                        $employeeRequisition = $endorsement->sourceRequisition;
                        $employeeLine = $endorsement->requisitionItem;

                        if (! $employeeRequisition || ! $employeeLine) {
                            continue;
                        }

                        $stock = max(0, $this->stockService->getStock((int) $endorsement->item_id, (int) $consolidated->office_id));

                        $consolidatedLine = RequisitionItem::query()
                            ->where('requisition_id', $consolidated->id)
                            ->where('item_id', $endorsement->item_id)
                            ->first();

                        $this->createIssuanceRecord(
                            batch: $batch,
                            requisition: $employeeRequisition,
                            line: $employeeLine,
                            qtyToIssue: $qtyToIssue,
                            issuanceDate: $issuanceDate,
                            custodian: $custodian,
                            issuedToUserId: $employeeId,
                            signatories: $signatories,
                            stock: $stock,
                            remarks: $entry['remarks'],
                            consolidatedRequisitionId: $consolidated->id,
                            sourceEndorsementId: $endorsement->id,
                        );

                        if ($consolidatedLine) {
                            $consolidatedLine->update([
                                'quantity_issued' => (int) ($consolidatedLine->quantity_issued ?? 0) + $qtyToIssue,
                                'stock_available' => $stock,
                            ]);
                        }

                        $categoryName = $endorsement->item?->category?->name ?? 'Other';
                        $categoryCounts[$categoryName] = ($categoryCounts[$categoryName] ?? 0) + 1;
                        $created++;
                        $employeeRequisitionsToClose[(int) $employeeRequisition->id] = true;
                    }
                });

            if ($created > 0) {
                $consolidated->refresh();
                $consolidated->load('items');

                $consolidated->update([
                    'approved_by' => $custodian->id,
                    'approved_at' => now(),
                    'status' => $this->resolveStatusAfterIssue($consolidated),
                ]);
            }
        });

        foreach (array_keys($employeeRequisitionsToClose) as $employeeRequisitionId) {
            $employeeRequisition = Requisition::query()->find($employeeRequisitionId);
            if ($employeeRequisition) {
                app(EmployeeRequisitionClosureService::class)->closeFromRequisition($employeeRequisition);
            }
        }

        return $this->finalizeIssueResult($consolidated, $custodian, $created, $acknowledged, $categoryCounts);
    }

    /**
     * @param  array<string, mixed>  $signatories
     */
    protected function createIssuanceBatch(
        Requisition $requisition,
        User $custodian,
        string $categorySlug,
        string $issuanceDate,
        int $paperworkIssuedToUserId,
        array $signatories,
    ): IssuanceBatch {
        $batchPayload = [
            'category_slug' => $categorySlug,
            'requisition_id' => $requisition->id,
            'office_id' => $requisition->office_id,
            'department_id' => $requisition->department_id,
            'issuance_date' => $issuanceDate,
            'issued_to' => $paperworkIssuedToUserId,
            'issued_by' => $custodian->id,
        ];

        foreach (['custodian_printed_name', 'custodian_designation', 'issued_to_designation', 'accounting_staff_printed_name', 'received_from_name'] as $signatoryField) {
            if (filled($signatories[$signatoryField] ?? null)) {
                $batchPayload[$signatoryField] = (string) $signatories[$signatoryField];
            }
        }

        return IssuanceBatch::create($batchPayload);
    }

    /**
     * @param  array<string, mixed>  $signatories
     */
    protected function createIssuanceRecord(
        IssuanceBatch $batch,
        Requisition $requisition,
        RequisitionItem $line,
        int $qtyToIssue,
        string $issuanceDate,
        User $custodian,
        int $issuedToUserId,
        array $signatories,
        int $stock,
        ?string $remarks = null,
        ?int $consolidatedRequisitionId = null,
        ?int $sourceEndorsementId = null,
    ): void {
        $issuancePayload = [
            'issuance_batch_id' => $batch->id,
            'requisition_id' => $requisition->id,
            'consolidated_requisition_id' => $consolidatedRequisitionId,
            'source_endorsement_id' => $sourceEndorsementId,
            'office_id' => $requisition->office_id,
            'department_id' => $requisition->department_id,
            'item_id' => $line->item_id,
            'quantity' => $qtyToIssue,
            'issuance_date' => $issuanceDate,
            'issued_to' => $issuedToUserId,
            'issued_by' => $custodian->id,
        ];

        if ($line->item?->category?->getTemplateSlug() === 'semi_expendable') {
            $issuancePayload['estimated_useful_life'] = SemiExpendableUsefulLife::resolveForItem($line->item);
        }

        foreach (['custodian_printed_name', 'custodian_designation', 'issued_to_designation', 'accounting_staff_printed_name', 'received_from_name'] as $signatoryField) {
            if (filled($signatories[$signatoryField] ?? null)) {
                $issuancePayload[$signatoryField] = (string) $signatories[$signatoryField];
            }
        }

        Issuance::create($issuancePayload);

        $line->update([
            'quantity_issued' => (int) ($line->quantity_issued ?? 0) + $qtyToIssue,
            'stock_available' => $stock,
            'issue_remarks' => $remarks ?? $line->issue_remarks,
        ]);
    }

    /**
     * @param  array<string, int>  $categoryCounts
     * @return array{created: int, acknowledged: int, categories: array<string, int>}
     */
    protected function finalizeIssueResult(
        Requisition $requisition,
        User $custodian,
        int $created,
        int $acknowledged,
        array $categoryCounts,
    ): array {
        if ($created > 0) {
            app(RequisitionWorkflowNotificationService::class)->handleCustodianIssued($requisition->fresh());
            app(UserActivityLogger::class)->record(
                $custodian,
                'issued',
                'Issued '.$created.' line'.($created === 1 ? '' : 's').' for requisition '.$requisition->reference_code,
                $requisition,
                [
                    'created' => $created,
                    'acknowledged' => $acknowledged,
                    'categories' => $categoryCounts,
                ],
            );
        }

        if ($acknowledged > 0) {
            app(RequisitionWorkflowNotificationService::class)->handleBackorderAcknowledged($requisition->fresh());
        }

        return [
            'created' => $created,
            'acknowledged' => $acknowledged,
            'categories' => $categoryCounts,
        ];
    }

    public function reject(Requisition $requisition, User $custodian, string $remarks): void
    {
        $requisition->update([
            'status' => Requisition::STATUS_REJECTED,
            'remarks' => $remarks,
            'approved_by' => $custodian->id,
            'approved_at' => now(),
        ]);

        app(RequisitionWorkflowNotificationService::class)->handleCustodianRejected($requisition->fresh());
        app(UserActivityLogger::class)->record(
            $custodian,
            'rejected',
            'Rejected requisition '.$requisition->reference_code,
            $requisition,
            ['remarks' => $remarks],
        );
    }
}
