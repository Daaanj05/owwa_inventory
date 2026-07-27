<?php

namespace App\Services;

use App\Models\Distribution;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\RequisitionSourceEndorsement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DistributionCompileService
{
    public function __construct(
        protected OfficeDistributionBalanceService $balanceService,
    ) {}

    /**
     * @return array<int, string>
     */
    public function eligibleEmployeeRequisitionOptions(
        User $unitConsolidator,
        ?int $officeId,
        ?int $departmentId,
    ): array {
        return $this->eligibleEmployeeRequisitionsQuery($unitConsolidator, $officeId, $departmentId)
            ->latest('created_at')
            ->get()
            ->filter(fn (Requisition $requisition): bool => $requisition->items->contains(
                fn (RequisitionItem $line): bool => $this->remainingQuantityForLine($line) > 0,
            ))
            ->mapWithKeys(function (Requisition $requisition): array {
                $reference = $requisition->transaction_number ?? "#{$requisition->id}";
                $employee = $requisition->requestedBy?->name ?? 'Employee';
                $remaining = $requisition->items->sum(
                    fn (RequisitionItem $line): int => $this->remainingQuantityForLine($line),
                );

                return [$requisition->id => "{$reference} — {$employee} ({$remaining} remaining)"];
            })
            ->all();
    }

    public function eligibleEmployeeRequisitionsQuery(
        User $unitConsolidator,
        ?int $officeId,
        ?int $departmentId,
    ): Builder {
        $query = $unitConsolidator->applyUnitConsolidatorRequisitionScope(
            Requisition::query(),
        )
            ->where('status', Requisition::STATUS_ACCEPTED)
            ->whereNull('closed_at')
            ->whereHas('requestedBy', fn (Builder $query): Builder => $query->where('role', User::ROLE_EMPLOYEE))
            ->with(['items.item', 'requestedBy']);

        if ($officeId !== null && $officeId > 0) {
            $query->where('office_id', $officeId);
        }

        if ($departmentId !== null && $departmentId > 0) {
            $query->where('department_id', $departmentId);
        }

        return $query;
    }

    /**
     * @param  Collection<int, Requisition>|SupportCollection<int, Requisition>  $requisitions
     * @return array<int, array<string, mixed>>
     */
    public function buildDistributionLines(
        Collection|SupportCollection $requisitions,
        int $officeId,
    ): array {
        if (! $requisitions instanceof Collection) {
            $requisitions = new Collection($requisitions->all());
        }

        $requisitions->loadMissing(['items.item', 'requestedBy']);
        $availableByItem = [];
        $allocatedByItem = [];
        $lines = [];

        foreach ($requisitions as $requisition) {
            foreach ($requisition->items as $line) {
                $remaining = $this->remainingQuantityForLine($line);

                if ($remaining <= 0) {
                    continue;
                }

                $itemId = (int) $line->item_id;
                $availableByItem[$itemId] ??= $this->balanceService->availableQuantity($itemId, $officeId);
                $allocatedByItem[$itemId] ??= 0;
                $quantity = min($remaining, max(0, $availableByItem[$itemId] - $allocatedByItem[$itemId]));
                $allocatedByItem[$itemId] += $quantity;

                $lines[] = [
                    'source_requisition_id' => (int) $requisition->id,
                    'requisition_item_id' => (int) $line->id,
                    'employee_id' => (int) $requisition->requested_by,
                    'employee_name' => $requisition->requestedBy?->name ?? 'Employee',
                    'transaction_number' => $requisition->transaction_number ?? "#{$requisition->id}",
                    'purpose' => $requisition->purpose,
                    'item_id' => $itemId,
                    'item_name' => $line->item?->name ?? "Item #{$itemId}",
                    'available_quantity' => $availableByItem[$itemId],
                    'requested_quantity' => $this->targetQuantityForLine($line),
                    'remaining_quantity' => $remaining,
                    'quantity' => $quantity,
                    'remarks' => null,
                ];
            }
        }

        return $lines;
    }

    public function targetQuantityForLine(RequisitionItem $line): int
    {
        $endorsement = RequisitionSourceEndorsement::query()
            ->where('source_requisition_id', $line->requisition_id)
            ->where('requisition_item_id', $line->id)
            ->first();

        return (int) ($endorsement?->endorsed_quantity ?? $line->quantity);
    }

    public function remainingQuantityForLine(RequisitionItem $line): int
    {
        $line->loadMissing('requisition');
        $target = $this->targetQuantityForLine($line);
        $linkedDistributed = (int) Distribution::query()
            ->where('requisition_item_id', $line->id)
            ->sum('quantity');
        $itemRemaining = max(
            0,
            $line->requisition === null
                ? $target
                : \App\Support\EmployeeRequisitionStatus::remainingToFulfillForItem(
                    $line->requisition,
                    (int) $line->item_id,
                ),
        );

        return min(max(0, $target - $linkedDistributed), $itemRemaining);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function validateDistributionLines(
        User $unitConsolidator,
        int $officeId,
        int $departmentId,
        array $lines,
    ): void {
        $errors = [];
        $quantityByItem = [];
        $quantityByRequisitionItem = [];
        $quantityByRequisitionAndItem = [];

        if (! $unitConsolidator->coversOfficeDepartment($officeId, $departmentId)) {
            $errors['office_id'] = 'The selected office and department are not in your assignments.';
        }

        if ($lines === []) {
            $errors['distribution_lines'] = 'Select at least one employee requisition to distribute.';
        }

        foreach (array_values($lines) as $index => $row) {
            $line = RequisitionItem::query()
                ->with(['requisition.requestedBy'])
                ->find((int) ($row['requisition_item_id'] ?? 0));
            $quantity = (int) ($row['quantity'] ?? 0);

            if (
                ! $line instanceof RequisitionItem
                || ! $line->requisition instanceof Requisition
                || $line->requisition->status !== Requisition::STATUS_ACCEPTED
                || $line->requisition->closed_at !== null
                || (int) $line->requisition->office_id !== $officeId
                || (int) $line->requisition->department_id !== $departmentId
                || $line->requisition->requestedBy?->role !== User::ROLE_EMPLOYEE
                || (int) ($row['source_requisition_id'] ?? 0) !== (int) $line->requisition_id
                || (int) ($row['item_id'] ?? 0) !== (int) $line->item_id
                || (int) ($row['employee_id'] ?? 0) !== (int) $line->requisition->requested_by
            ) {
                $errors["distribution_lines.{$index}.quantity"] = 'This requisition line is no longer eligible for distribution.';

                continue;
            }

            if ($quantity <= 0) {
                $errors["distribution_lines.{$index}.quantity"] = 'Quantity to distribute must be at least 1.';

                continue;
            }

            $itemId = (int) $line->item_id;
            $requisitionItemId = (int) $line->id;
            $requisitionItemKey = "{$line->requisition_id}:{$itemId}";
            $quantityByItem[$itemId] = ($quantityByItem[$itemId] ?? 0) + $quantity;
            $quantityByRequisitionItem[$requisitionItemId] = ($quantityByRequisitionItem[$requisitionItemId] ?? 0) + $quantity;
            $quantityByRequisitionAndItem[$requisitionItemKey] = ($quantityByRequisitionAndItem[$requisitionItemKey] ?? 0) + $quantity;

            $lineRemaining = $this->remainingQuantityForLine($line);
            if ($quantityByRequisitionItem[$requisitionItemId] > $lineRemaining) {
                $errors["distribution_lines.{$index}.quantity"] = "Only {$lineRemaining} unit(s) remain for this requisition line.";
            }

            $itemRemaining = \App\Support\EmployeeRequisitionStatus::remainingToFulfillForItem(
                $line->requisition,
                $itemId,
            );
            if ($quantityByRequisitionAndItem[$requisitionItemKey] > $itemRemaining) {
                $errors["distribution_lines.{$index}.quantity"] = "Only {$itemRemaining} unit(s) remain for this requested item.";
            }

            $available = $this->balanceService->availableQuantity($itemId, $officeId);
            if ($quantityByItem[$itemId] > $available) {
                $errors["distribution_lines.{$index}.quantity"] = "Only {$available} unit(s) remain from SC issuance for this item.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return Collection<int, Distribution>
     */
    public function createDistributions(
        User $unitConsolidator,
        int $officeId,
        int $departmentId,
        string $distributionDate,
        array $lines,
    ): Collection {
        return DB::transaction(function () use ($unitConsolidator, $officeId, $departmentId, $distributionDate, $lines): Collection {
            $this->validateDistributionLines($unitConsolidator, $officeId, $departmentId, $lines);
            $distributions = new Collection;

            foreach ($lines as $row) {
                $distributions->push(Distribution::query()->create([
                    'office_id' => $officeId,
                    'department_id' => $departmentId,
                    'requisition_id' => (int) $row['source_requisition_id'],
                    'requisition_item_id' => (int) $row['requisition_item_id'],
                    'item_id' => (int) $row['item_id'],
                    'quantity' => (int) $row['quantity'],
                    'distributed_to' => (int) $row['employee_id'],
                    'distributed_by' => (int) $unitConsolidator->id,
                    'distribution_date' => $distributionDate,
                    'remarks' => filled($row['remarks'] ?? null) ? (string) $row['remarks'] : null,
                ]));
            }

            return $distributions;
        });
    }
}
