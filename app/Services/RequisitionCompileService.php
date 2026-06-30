<?php

namespace App\Services;

use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use InvalidArgumentException;

class RequisitionCompileService
{
    /**
     * @return array<int, string>
     */
    public function eligibleEmployeeRequisitionOptions(User $unitConsolidator): array
    {
        if (! $unitConsolidator->office_id) {
            return [];
        }

        return $this->eligibleEmployeeRequisitionsQuery($unitConsolidator)
            ->orderByDesc('created_at')
            ->get()
            ->mapWithKeys(function (Requisition $requisition): array {
                $ref = $requisition->reference_code ?? "#{$requisition->id}";
                $requester = $requisition->requestedBy?->name ?? 'Employee';

                return [$requisition->id => "{$ref} — {$requester}"];
            })
            ->all();
    }

    public function eligibleEmployeeRequisitionsQuery(User $unitConsolidator): Builder
    {
        return RequisitionResource::getEloquentQuery()
            ->where('office_id', $unitConsolidator->office_id)
            ->where('status', Requisition::STATUS_ACCEPTED)
            ->whereNull('compiled_into_requisition_id')
            ->whereHas('requestedBy', fn (Builder $query): Builder => $query->where('role', User::ROLE_EMPLOYEE))
            ->with('requestedBy');
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
     * @param  Collection<int, Requisition>  $employeeRequisitions
     * @return array<int, array{item_id: int, item_name: string, quantity: int, line_source_summary: string}>
     */
    public function mergedLineItems(Collection|SupportCollection $employeeRequisitions): array
    {
        if (! $employeeRequisitions instanceof Collection) {
            $employeeRequisitions = new Collection($employeeRequisitions->all());
        }

        $employeeRequisitions->loadMissing(['items.item']);

        /** @var array<int, array{item_id: int, item_name: string, quantity: int, sources: array<string, int>}> $merged */
        $merged = [];

        foreach ($employeeRequisitions as $requisition) {
            foreach ($requisition->items as $line) {
                $itemId = (int) $line->item_id;
                $qty = (int) $line->quantity;

                if (! isset($merged[$itemId])) {
                    $merged[$itemId] = [
                        'item_id' => $itemId,
                        'item_name' => $line->item?->name ?? "Item #{$itemId}",
                        'quantity' => 0,
                        'sources' => [],
                    ];
                }

                $merged[$itemId]['quantity'] += $qty;
                $ref = $requisition->reference_code ?? "#{$requisition->id}";
                $merged[$itemId]['sources'][$ref] = ($merged[$itemId]['sources'][$ref] ?? 0) + $qty;
            }
        }

        return collect($merged)
            ->map(function (array $row): array {
                $sourceParts = [];
                foreach ($row['sources'] as $ref => $qty) {
                    $sourceParts[] = "{$ref}: {$qty}";
                }

                return [
                    'item_id' => $row['item_id'],
                    'item_name' => $row['item_name'],
                    'quantity' => $row['quantity'],
                    'line_source_summary' => implode(', ', $sourceParts),
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
        return collect($mergedLineItems)
            ->map(function (array $row): array {
                $item = Item::query()->find($row['item_id']);

                return [
                    'item_category_id' => $item?->item_category_id,
                    'item_id' => $row['item_id'],
                    'quantity' => $row['quantity'],
                    'remarks' => null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Requisition>|SupportCollection<int, Requisition>|array<int, int>  $employeeRequisitions
     */
    public function linkCompiledSources(User $unitConsolidator, Requisition $consolidated, Collection|SupportCollection|array $employeeRequisitions): void
    {
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

        if ((int) $consolidated->office_id !== (int) $unitConsolidator->office_id) {
            throw new InvalidArgumentException('Consolidated requisition office does not match your office.');
        }

        $eligible->each(fn (Requisition $employeeRequisition) => $employeeRequisition->update([
            'compiled_into_requisition_id' => $consolidated->id,
        ]));
    }

    /**
     * @param  Collection<int, Requisition>|SupportCollection<int, Requisition>  $employeeRequisitions
     * @param  array<int, array{item_id?: int, quantity?: int, remarks?: string|null}>  $items
     */
    public function createConsolidatedRequisition(
        User $unitConsolidator,
        Collection|SupportCollection $employeeRequisitions,
        array $items,
        ?string $purpose = null,
    ): Requisition {
        if (! $employeeRequisitions instanceof Collection) {
            $employeeRequisitions = new Collection($employeeRequisitions->all());
        }

        if (! $unitConsolidator->office_id) {
            throw new InvalidArgumentException('Unit Consolidator must have an office assigned.');
        }

        $requisition = Requisition::create([
            'office_id' => $unitConsolidator->office_id,
            'department_id' => $unitConsolidator->department_id,
            'requested_by' => $unitConsolidator->id,
            'status' => Requisition::STATUS_PENDING,
            'purpose' => $purpose,
        ]);

        foreach ($items as $row) {
            if (empty($row['item_id']) || empty($row['quantity'])) {
                continue;
            }

            RequisitionItem::create([
                'requisition_id' => $requisition->id,
                'item_id' => (int) $row['item_id'],
                'quantity' => (int) $row['quantity'],
                'remarks' => filled($row['remarks'] ?? null) ? (string) $row['remarks'] : null,
            ]);
        }

        $this->linkCompiledSources($unitConsolidator, $requisition, $employeeRequisitions);

        return $requisition->load('items');
    }
}
