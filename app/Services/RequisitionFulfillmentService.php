<?php

namespace App\Services;

use App\Models\Issuance;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Support\SemiExpendableUsefulLife;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RequisitionFulfillmentService
{
    public function __construct(
        protected InventoryStockService $stockService,
    ) {}

    public function remainingQuantity(RequisitionItem $line): int
    {
        $issued = (int) ($line->quantity_issued ?? 0);

        return max(0, (int) $line->quantity - $issued);
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
     * @return array{created: int, categories: array<string, int>}
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

        $created = 0;

        /** @var array<string, int> $categoryCounts */
        $categoryCounts = [];

        DB::transaction(function () use ($requisition, $custodian, $rows, $issuanceDate, $signatories, &$created, &$categoryCounts): void {
            foreach ($rows as $row) {
                $lineId = (int) ($row['requisition_item_id'] ?? 0);
                $qtyToIssue = (int) ($row['quantity_to_issue'] ?? 0);

                if ($lineId <= 0 || $qtyToIssue <= 0) {
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

                $stock = max(0, $this->stockService->getStock((int) $line->item_id, (int) $requisition->office_id));
                $qtyToIssue = min($qtyToIssue, $stock);

                if ($qtyToIssue <= 0) {
                    continue;
                }

                $issuancePayload = [
                    'requisition_id' => $requisition->id,
                    'office_id' => $requisition->office_id,
                    'department_id' => $requisition->department_id,
                    'item_id' => $line->item_id,
                    'quantity' => $qtyToIssue,
                    'issuance_date' => $issuanceDate,
                    'issued_to' => $requisition->requested_by,
                    'issued_by' => $custodian->id,
                ];

                if ($line->item?->category?->getTemplateSlug() === 'semi_expendable') {
                    $issuancePayload['estimated_useful_life'] = SemiExpendableUsefulLife::resolveForItem($line->item);
                }

                foreach (['custodian_printed_name', 'custodian_designation', 'issued_to_designation', 'accounting_staff_printed_name'] as $signatoryField) {
                    if (filled($signatories[$signatoryField] ?? null)) {
                        $issuancePayload[$signatoryField] = (string) $signatories[$signatoryField];
                    }
                }

                Issuance::create($issuancePayload);

                $line->update([
                    'quantity_issued' => (int) ($line->quantity_issued ?? 0) + $qtyToIssue,
                    'stock_available' => $stock,
                    'issue_remarks' => filled($row['issue_remarks'] ?? null)
                        ? (string) $row['issue_remarks']
                        : $line->issue_remarks,
                ]);

                $categoryName = $line->item?->category?->name ?? 'Other';
                $categoryCounts[$categoryName] = ($categoryCounts[$categoryName] ?? 0) + 1;
                $created++;
            }

            if ($created > 0) {
                $requisition->refresh();
                $requisition->load('items');

                $requisition->update([
                    'approved_by' => $requisition->approved_by ?? $custodian->id,
                    'approved_at' => $requisition->approved_at ?? now(),
                    'status' => $this->resolveStatusAfterIssue($requisition),
                ]);
            }
        });

        if ($created > 0) {
            app(RequisitionWorkflowNotificationService::class)->handleCustodianIssued($requisition->fresh());
        }

        return [
            'created' => $created,
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
    }
}
