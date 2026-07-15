<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\RequisitionSourceEndorsement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RequisitionCompileService
{
    /**
     * @return array<int, string>
     */
    public function eligibleEmployeeRequisitionOptions(
        User $unitConsolidator,
        ?int $officeId = null,
        ?int $departmentId = null,
    ): array {
        if (! $unitConsolidator->office_id && $unitConsolidator->assignedOfficeIds() === []) {
            return [];
        }

        return $this->eligibleEmployeeRequisitionsQuery($unitConsolidator, $officeId, $departmentId)
            ->orderByDesc('created_at')
            ->get()
            ->mapWithKeys(function (Requisition $requisition): array {
                $ref = $requisition->transaction_number ?? "#{$requisition->id}";
                $requester = $requisition->requestedBy?->name ?? 'Employee';
                $backorderHint = $this->backorderHintForRequisition($requisition);
                $label = "{$ref} — {$requester}";

                if ($backorderHint !== null) {
                    $label .= " ({$backorderHint})";
                }

                return [$requisition->id => $label];
            })
            ->all();
    }

    public function backorderHintForRequisition(Requisition $requisition): ?string
    {
        $requisition->loadMissing('items');

        $backorderedLines = $requisition->items->filter(
            fn (RequisitionItem $line): bool => $line->stock_at_request !== null
                ? $line->isBackordered()
                : app(RequisitionStockSnapshotService::class)->regionalStockForItem((int) $line->item_id) < (int) $line->quantity,
        )->count();

        if ($backorderedLines === 0) {
            return null;
        }

        return $backorderedLines === 1
            ? '1 line awaiting stock'
            : "{$backorderedLines} lines awaiting stock";
    }

    public function eligibleEmployeeRequisitionsQuery(
        User $unitConsolidator,
        ?int $officeId = null,
        ?int $departmentId = null,
    ): Builder {
        $query = $unitConsolidator->applyUnitConsolidatorRequisitionScope(
            Requisition::query()
        )
            ->where('status', Requisition::STATUS_ACCEPTED)
            ->whereNull('compiled_into_requisition_id')
            ->whereHas('requestedBy', fn (Builder $query): Builder => $query->where('role', User::ROLE_EMPLOYEE))
            ->with('requestedBy');

        if ($officeId !== null && $officeId > 0) {
            $query->where('office_id', $officeId);
        }

        if ($departmentId !== null && $departmentId > 0) {
            $query->where('department_id', $departmentId);
        }

        return $query;
    }

    /**
     * @param  Collection<int, Requisition>|SupportCollection<int, Requisition>  $records
     * @return Collection<int, Requisition>
     */
    public function filterEligible(Collection|SupportCollection $records): Collection
    {
        if (! $records instanceof Collection) {
            $records = new Collection($records->all());
        }

        $records->loadMissing(['requestedBy', 'items.item']);

        return $records
            ->filter(fn (Requisition $requisition): bool => $requisition->status === Requisition::STATUS_ACCEPTED)
            ->filter(fn (Requisition $requisition): bool => $requisition->compiled_into_requisition_id === null)
            ->filter(fn (Requisition $requisition): bool => $requisition->requestedBy?->role === User::ROLE_EMPLOYEE)
            ->values();
    }

    /**
     * @param  Collection<int, Requisition>|SupportCollection<int, Requisition>  $employeeRequisitions
     * @return array<int, array<string, mixed>>
     */
    public function buildEndorsementLines(Collection|SupportCollection $employeeRequisitions): array
    {
        if (! $employeeRequisitions instanceof Collection) {
            $employeeRequisitions = new Collection($employeeRequisitions->all());
        }

        $employeeRequisitions->loadMissing(['items.item.category', 'requestedBy']);

        $lines = [];

        foreach ($employeeRequisitions as $requisition) {
            $ref = $requisition->transaction_number ?? "#{$requisition->id}";
            $employeeName = $requisition->requestedBy?->name ?? 'Employee';

            foreach ($requisition->items as $line) {
                $requested = (int) $line->quantity;

                $lines[] = [
                    'source_requisition_id' => $requisition->id,
                    'requisition_item_id' => $line->id,
                    'item_id' => (int) $line->item_id,
                    'item_category_id' => $line->item?->item_category_id,
                    'item_name' => $line->item?->name ?? "Item #{$line->item_id}",
                    'employee_name' => $employeeName,
                    'transaction_number' => $ref,
                    'purpose' => $requisition->purpose,
                    'requested_quantity' => $requested,
                    'endorsed_quantity' => $requested,
                    'employee_remarks' => null,
                ];
            }
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $endorsementLines
     */
    public function validateEndorsementLines(array $endorsementLines): void
    {
        $errors = [];

        foreach (array_values($endorsementLines) as $index => $line) {
            $requested = (int) ($line['requested_quantity'] ?? 0);
            $endorsed = (int) ($line['endorsed_quantity'] ?? 0);

            if ($endorsed < 0 || $endorsed > $requested) {
                $errors["endorsement_lines.{$index}.endorsed_quantity"] = 'Endorsed quantity must be between 0 and the requested quantity.';
            }

            if ($endorsed < $requested && blank($line['employee_remarks'] ?? null)) {
                $errors["endorsement_lines.{$index}.employee_remarks"] = 'Add a remark to the employee when endorsing less than requested.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $endorsementLines
     * @return array<int, array{item_id: int, item_name: string, quantity: int, requested_total: int, line_source_summary: string, allocation_summary: string}>
     */
    public function mergedLineItemsFromEndorsements(array $endorsementLines): array
    {
        /** @var array<int, array{item_id: int, item_name: string, quantity: int, requested_total: int, allocations: array<string, array{employee: string, ref: string, endorsed: int}>}> $merged */
        $merged = [];

        foreach ($endorsementLines as $line) {
            $endorsed = (int) ($line['endorsed_quantity'] ?? 0);

            if ($endorsed <= 0) {
                continue;
            }

            $itemId = (int) $line['item_id'];
            $requested = (int) ($line['requested_quantity'] ?? 0);
            $employee = (string) ($line['employee_name'] ?? 'Employee');
            $ref = (string) ($line['transaction_number'] ?? '');
            $allocationKey = "{$employee}|{$ref}";

            if (! isset($merged[$itemId])) {
                $merged[$itemId] = [
                    'item_id' => $itemId,
                    'item_name' => (string) ($line['item_name'] ?? "Item #{$itemId}"),
                    'quantity' => 0,
                    'requested_total' => 0,
                    'allocations' => [],
                ];
            }

            $merged[$itemId]['quantity'] += $endorsed;
            $merged[$itemId]['requested_total'] += $requested;
            $merged[$itemId]['allocations'][$allocationKey] = [
                'employee' => $employee,
                'ref' => $ref,
                'endorsed' => ($merged[$itemId]['allocations'][$allocationKey]['endorsed'] ?? 0) + $endorsed,
            ];
        }

        return collect($merged)
            ->map(function (array $row): array {
                $allocationParts = [];
                foreach ($row['allocations'] as $allocation) {
                    $allocationParts[] = "{$allocation['employee']} ({$allocation['ref']}): {$allocation['endorsed']} endorsed";
                }

                return [
                    'item_id' => $row['item_id'],
                    'item_name' => $row['item_name'],
                    'quantity' => $row['quantity'],
                    'requested_total' => $row['requested_total'],
                    'line_source_summary' => implode(', ', $allocationParts),
                    'allocation_summary' => implode(' · ', $allocationParts),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $mergedLineItems
     * @return array<int, array<string, mixed>>
     */
    public function mergedLineItemsAsRepeaterState(array $mergedLineItems): array
    {
        $snapshotService = app(RequisitionStockSnapshotService::class);

        return collect($mergedLineItems)
            ->map(function (array $row) use ($snapshotService): array {
                $item = Item::query()->find($row['item_id']);
                $itemId = (int) $row['item_id'];

                return [
                    'item_category_id' => $item?->item_category_id,
                    'item_id' => $itemId,
                    'quantity' => $row['quantity'],
                    'requested_total' => $row['requested_total'] ?? $row['quantity'],
                    'allocation_summary' => $row['allocation_summary'] ?? $row['line_source_summary'] ?? '',
                    'stock_at_request' => $snapshotService->regionalStockForItem($itemId),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Requisition>|SupportCollection<int, Requisition>  $employeeRequisitions
     * @return array<int, array{item_id: int, item_name: string, quantity: int, line_source_summary: string}>
     */
    public function mergedLineItems(Collection|SupportCollection $employeeRequisitions): array
    {
        return $this->mergedLineItemsFromEndorsements(
            $this->buildEndorsementLines($employeeRequisitions),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $endorsementLines
     */
    public function persistSourceEndorsements(
        Requisition $consolidated,
        array $endorsementLines,
    ): void {
        foreach ($endorsementLines as $line) {
            $endorsed = (int) ($line['endorsed_quantity'] ?? 0);

            if ($endorsed <= 0) {
                continue;
            }

            RequisitionSourceEndorsement::query()->create([
                'consolidated_requisition_id' => $consolidated->id,
                'source_requisition_id' => (int) $line['source_requisition_id'],
                'requisition_item_id' => (int) $line['requisition_item_id'],
                'requested_by_user_id' => (int) Requisition::query()
                    ->whereKey((int) $line['source_requisition_id'])
                    ->value('requested_by'),
                'item_id' => (int) $line['item_id'],
                'requested_quantity' => (int) ($line['requested_quantity'] ?? 0),
                'endorsed_quantity' => $endorsed,
                'employee_remarks' => filled($line['employee_remarks'] ?? null)
                    ? (string) $line['employee_remarks']
                    : null,
            ]);
        }
    }

    /**
     * @param  SupportCollection<int, RequisitionSourceEndorsement>|Collection<int, RequisitionSourceEndorsement>  $endorsements
     */
    public function formatAllocationSummary(Collection|SupportCollection $endorsements): string
    {
        if (! $endorsements instanceof Collection) {
            $endorsements = new Collection($endorsements->all());
        }

        $endorsements->loadMissing(['requestedBy', 'sourceRequisition']);

        return $endorsements
            ->groupBy(fn (RequisitionSourceEndorsement $row): string => (string) $row->requested_by_user_id)
            ->map(function (Collection $group): string {
                $first = $group->first();
                $employee = $first?->requestedBy?->name ?? 'Employee';
                $ref = $first?->sourceRequisition?->transaction_number ?? "#{$first?->source_requisition_id}";
                $endorsed = (int) $group->sum('endorsed_quantity');

                return "{$employee} ({$ref}): {$endorsed} endorsed";
            })
            ->values()
            ->implode(' · ');
    }

    /**
     * @param  Collection<int, Requisition>|SupportCollection<int, Requisition>|array<int, int>  $employeeRequisitions
     * @param  array<int, array<string, mixed>>  $endorsementLines
     */
    public function linkCompiledSources(
        User $unitConsolidator,
        Requisition $consolidated,
        Collection|SupportCollection|array $employeeRequisitions,
        array $endorsementLines = [],
    ): void {
        if (is_array($employeeRequisitions)) {
            $employeeRequisitions = Requisition::query()
                ->whereIn('id', $employeeRequisitions)
                ->get();
        }

        if (! $employeeRequisitions instanceof Collection) {
            $employeeRequisitions = new Collection($employeeRequisitions->all());
        }

        $eligible = $this->filterEligible($employeeRequisitions);

        if ($eligible->isEmpty()) {
            throw new InvalidArgumentException('No eligible approved employee requisitions were selected.');
        }

        if ($eligible->count() !== $employeeRequisitions->count()) {
            throw new InvalidArgumentException('Only approved, uncompiled Employee requisitions can be included.');
        }

        if (! $unitConsolidator->coversOfficeDepartment(
            (int) $consolidated->office_id,
            (int) $consolidated->department_id,
        )) {
            throw new InvalidArgumentException('Consolidated requisition office and department are not in your assignments.');
        }

        if ($endorsementLines !== []) {
            $this->validateEndorsementLines($endorsementLines);
            $this->persistSourceEndorsements($consolidated, $endorsementLines);
        }

        $eligible->each(fn (Requisition $employeeRequisition) => $employeeRequisition->update([
            'compiled_into_requisition_id' => $consolidated->id,
            'endorsed_at' => now(),
            'endorsed_by' => $unitConsolidator->id,
        ]));
    }

    /**
     * @param  Collection<int, Requisition>|SupportCollection<int, Requisition>  $employeeRequisitions
     * @param  array<int, array{item_id?: int, quantity?: int}>  $items
     */
    public function createConsolidatedRequisition(
        User $unitConsolidator,
        Collection|SupportCollection $employeeRequisitions,
        array $items,
        ?string $purpose = null,
        ?int $officeId = null,
        ?int $departmentId = null,
        array $endorsementLines = [],
    ): Requisition {
        if (! $employeeRequisitions instanceof Collection) {
            $employeeRequisitions = new Collection($employeeRequisitions->all());
        }

        $officeId = $officeId ?? (int) $unitConsolidator->office_id;
        $departmentId = $departmentId ?? (int) $unitConsolidator->department_id;

        if ($officeId <= 0) {
            throw new InvalidArgumentException('Unit Consolidator must have an office assigned.');
        }

        $requisition = Requisition::create([
            'office_id' => $officeId,
            'department_id' => $departmentId > 0 ? $departmentId : null,
            'requested_by' => $unitConsolidator->id,
            'status' => Requisition::STATUS_PENDING,
            'purpose' => $purpose,
        ]);

        foreach ($items as $row) {
            if (empty($row['item_id']) || empty($row['quantity'])) {
                continue;
            }

            $itemId = (int) $row['item_id'];
            $stockAtRequest = array_key_exists('stock_at_request', $row)
                ? ($row['stock_at_request'] !== null ? (int) $row['stock_at_request'] : null)
                : app(RequisitionStockSnapshotService::class)->regionalStockForItem($itemId);

            RequisitionItem::create([
                'requisition_id' => $requisition->id,
                'item_id' => $itemId,
                'quantity' => (int) $row['quantity'],
                'stock_at_request' => $stockAtRequest,
            ]);
        }

        $this->linkCompiledSources($unitConsolidator, $requisition, $employeeRequisitions, $endorsementLines);

        return $requisition->load(['items', 'sourceEndorsements']);
    }
}
