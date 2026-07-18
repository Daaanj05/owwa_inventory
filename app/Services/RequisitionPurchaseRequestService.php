<?php

namespace App\Services;

use App\Models\AcquisitionPaperwork;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequisitionPurchaseRequestService
{
    /**
     * @var array<int, int>
     */
    protected array $currentRegionalStockByItem = [];

    public function __construct(
        protected RequisitionStockSnapshotService $stockSnapshotService,
        protected RequisitionFulfillmentService $fulfillmentService,
    ) {}

    public function isEligibleRequisition(Requisition $requisition): bool
    {
        $requisition->loadMissing('requestedBy');

        return $requisition->requestedBy?->role === User::ROLE_UNIT_CONSOLIDATOR
            && in_array($requisition->status, [
                Requisition::STATUS_PENDING,
                Requisition::STATUS_ACCEPTED,
            ], true);
    }

    public function alreadySourcedQuantity(
        RequisitionItem $line,
        ?int $excludingPaperworkId = null,
    ): int {
        return (int) DB::table('acquisition_paperwork_line_requisition_item as source')
            ->join(
                'acquisition_paperwork_lines as paperwork_lines',
                'paperwork_lines.id',
                '=',
                'source.acquisition_paperwork_line_id',
            )
            ->where('source.requisition_item_id', $line->id)
            ->when(
                $excludingPaperworkId !== null,
                fn ($query) => $query->where(
                    'paperwork_lines.acquisition_paperwork_id',
                    '!=',
                    $excludingPaperworkId,
                ),
            )
            ->sum('source.quantity');
    }

    public function remainingQuantityToSource(
        RequisitionItem $line,
        ?int $excludingPaperworkId = null,
    ): int {
        return max(
            0,
            $this->fulfillmentService->remainingQuantity($line)
                - $this->alreadySourcedQuantity($line, $excludingPaperworkId),
        );
    }

    public function canCreatePurchaseRequest(Requisition $requisition): bool
    {
        return $this->eligibleCategoryIds($requisition) !== [];
    }

    /**
     * @return array<int, int>
     */
    public function eligibleCategoryIds(Requisition $requisition): array
    {
        if (! $this->isEligibleRequisition($requisition)) {
            return [];
        }

        return $this->eligibleSourceLines($requisition)
            ->pluck('item.item_category_id')
            ->filter()
            ->map(fn (mixed $categoryId): int => (int) $categoryId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function eligibleCategoryOptions(Requisition $requisition): array
    {
        $requisition->loadMissing('items.item.category');

        return $requisition->items
            ->filter(fn (RequisitionItem $line): bool => in_array(
                (int) $line->item?->item_category_id,
                $this->eligibleCategoryIds($requisition),
                true,
            ))
            ->mapWithKeys(fn (RequisitionItem $line): array => [
                (int) $line->item->item_category_id => (string) ($line->item->category?->name ?? 'Category'),
            ])
            ->all();
    }

    /**
     * @return Collection<int, RequisitionItem>
     */
    public function eligibleSourceLines(Requisition $requisition, ?int $categoryId = null): Collection
    {
        if (! $this->isEligibleRequisition($requisition)) {
            return collect();
        }

        $requisition->loadMissing('items.item.category');

        return $requisition->items
            ->filter(function (RequisitionItem $line) use ($categoryId): bool {
                if ($line->item === null) {
                    return false;
                }

                if ($categoryId !== null && (int) $line->item->item_category_id !== $categoryId) {
                    return false;
                }

                return $this->remainingQuantityToSource($line) > 0
                    && $this->currentRegionalStockForItem((int) $line->item_id) === 0;
            })
            ->values();
    }

    protected function currentRegionalStockForItem(int $itemId): int
    {
        return $this->currentRegionalStockByItem[$itemId]
            ??= $this->stockSnapshotService->regionalStockForItem($itemId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildLinePayload(Requisition $requisition, int $categoryId): array
    {
        return $this->eligibleSourceLines($requisition, $categoryId)
            ->groupBy('item_id')
            ->map(function (Collection $lines): array {
                /** @var RequisitionItem $first */
                $first = $lines->first();

                return [
                    'item_id' => (int) $first->item_id,
                    'description' => (string) ($first->item?->name ?? ''),
                    'unit' => (string) ($first->item?->unit ?? ''),
                    'quantity' => $lines->sum(fn (RequisitionItem $line): int => $this->remainingQuantityToSource($line)),
                    'unit_cost' => null,
                    'amount' => null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Build the PR lines for manually linked requisitions. Unlike the zero-stock
     * shortcut, this includes every remaining requested line in the PR category.
     *
     * @param  array<int, int|string>  $requisitionIds
     * @return array<int, array<string, mixed>>
     */
    public function buildLinkedLinePayload(
        array $requisitionIds,
        int $categoryId,
        ?int $excludingPaperworkId = null,
    ): array {
        return $this->linkedSourceLines(
            $requisitionIds,
            $categoryId,
            $excludingPaperworkId,
        )
            ->groupBy('item_id')
            ->map(function (Collection $lines) use ($excludingPaperworkId): array {
                /** @var RequisitionItem $first */
                $first = $lines->first();

                return [
                    'item_id' => (int) $first->item_id,
                    'description' => (string) ($first->item?->name ?? ''),
                    'unit' => (string) ($first->item?->unit ?? ''),
                    'quantity' => $lines->sum(
                        fn (RequisitionItem $line): int => $this->remainingQuantityToSource(
                            $line,
                            $excludingPaperworkId,
                        ),
                    ),
                    'unit_cost' => null,
                    'amount' => null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function prefillState(Requisition $requisition, int $categoryId): array
    {
        $lines = $this->buildLinePayload($requisition, $categoryId);

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'These requisition lines are no longer eligible. Current regional stock must be zero and an unsourced quantity must remain.',
            ]);
        }

        return [
            'item_category_id' => $categoryId,
            'purpose' => $requisition->purpose,
            'requisitions' => [(int) $requisition->id],
            'lines' => $lines,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $submittedLines
     */
    public function validateShortcutLines(
        Requisition $requisition,
        int $categoryId,
        array $submittedLines,
    ): void {
        $expected = collect($this->buildLinePayload($requisition, $categoryId))
            ->mapWithKeys(fn (array $line): array => [(int) $line['item_id'] => (int) $line['quantity']])
            ->all();
        $submitted = collect($submittedLines)
            ->filter(fn (mixed $line): bool => is_array($line))
            ->mapWithKeys(fn (array $line): array => [(int) ($line['item_id'] ?? 0) => (int) ($line['quantity'] ?? 0)])
            ->all();

        if ($expected === [] || $submitted !== $expected) {
            throw ValidationException::withMessages([
                'lines' => 'The prefilled PR lines changed or are no longer eligible. Reopen Create PR to refresh current zero-stock quantities.',
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    public function eligibleRequisitionOptions(?string $search = null): array
    {
        $query = Requisition::query()
            ->whereIn('status', [Requisition::STATUS_PENDING, Requisition::STATUS_ACCEPTED])
            ->whereHas('requestedBy', fn ($query) => $query->where('role', User::ROLE_UNIT_CONSOLIDATOR))
            ->with(['office', 'items.item']);

        if (filled($search)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $search).'%';
            $query->where(function ($query) use ($term): void {
                $query->where('reference_code', 'like', $term)
                    ->orWhereHas('office', fn ($officeQuery) => $officeQuery->where('name', 'like', $term));
            });
        }

        return $query
            ->limit(100)
            ->get()
            ->map(function (Requisition $requisition): array {
                $zeroStockCount = $this->eligibleSourceLines($requisition)->count();
                $label = sprintf(
                    '%s · %s · %d zero',
                    $requisition->reference_code ?: "Requisition #{$requisition->id}",
                    $requisition->office?->name ?? 'No office',
                    $zeroStockCount,
                );

                return [
                    'id' => (int) $requisition->id,
                    'label' => $label,
                    'zero_stock_count' => $zeroStockCount,
                    'reference' => (string) $requisition->reference_code,
                ];
            })
            ->sortBy([
                ['zero_stock_count', 'desc'],
                ['reference', 'asc'],
            ])
            ->pluck('label', 'id')
            ->all();
    }

    /**
     * Zero-stock UC requisitions for the PR "See Requisitions" picker table.
     *
     * @param  array<int, int|string>  $includeIds
     * @return list<array{
     *     id: int,
     *     reference: string,
     *     requested_by: string,
     *     office: string,
     *     quantity_requested: int,
     *     regional_stock: int
     * }>
     */
    public function zeroStockRequisitionPickerRows(
        ?int $categoryId = null,
        array $includeIds = [],
    ): array {
        $includeIds = collect($includeIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return Requisition::query()
            ->whereIn('status', [Requisition::STATUS_PENDING, Requisition::STATUS_ACCEPTED])
            ->whereHas('requestedBy', fn ($query) => $query->where('role', User::ROLE_UNIT_CONSOLIDATOR))
            ->with(['office', 'requestedBy', 'items.item'])
            ->limit(100)
            ->get()
            ->map(function (Requisition $requisition) use ($categoryId, $includeIds): ?array {
                $zeroStockLines = $this->eligibleSourceLines($requisition, $categoryId);
                $includeExisting = in_array((int) $requisition->id, $includeIds, true);

                if ($zeroStockLines->isEmpty() && ! $includeExisting) {
                    return null;
                }

                $quantity = $zeroStockLines->isNotEmpty()
                    ? $zeroStockLines->sum(
                        fn (RequisitionItem $line): int => $this->remainingQuantityToSource($line),
                    )
                    : $requisition->items->sum(
                        fn (RequisitionItem $line): int => $this->remainingQuantityToSource($line),
                    );

                return [
                    'id' => (int) $requisition->id,
                    'reference' => (string) ($requisition->reference_code ?: "REQ #{$requisition->id}"),
                    'requested_by' => (string) ($requisition->requestedBy?->name ?? '—'),
                    'office' => (string) ($requisition->office?->name ?? '—'),
                    'quantity_requested' => (int) $quantity,
                    'regional_stock' => 0,
                ];
            })
            ->filter()
            ->sortBy('reference')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int|string>  $requisitionIds
     * @return Collection<int, RequisitionItem>
     */
    public function linkedSourceLines(
        array $requisitionIds,
        int $categoryId,
        ?int $excludingPaperworkId = null,
    ): Collection {
        $ids = collect($requisitionIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty() || $categoryId <= 0) {
            return collect();
        }

        $requisitions = Requisition::query()
            ->whereKey($ids->all())
            ->with(['requestedBy', 'items.item.category'])
            ->get();

        if ($requisitions->count() !== $ids->count()
            || $requisitions->contains(fn (Requisition $requisition): bool => ! $this->isEligibleRequisition($requisition))) {
            throw ValidationException::withMessages([
                'requisitions' => 'One or more linked requisitions are no longer eligible.',
            ]);
        }

        return $requisitions
            ->flatMap(fn (Requisition $requisition): Collection => $requisition->items)
            ->filter(function (RequisitionItem $line) use ($categoryId, $excludingPaperworkId): bool {
                return (int) $line->item?->item_category_id === $categoryId
                    && $this->remainingQuantityToSource($line, $excludingPaperworkId) > 0;
            })
            ->sortBy([
                ['requisition_id', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
    }

    /**
     * @param  array<int, int|string>  $requisitionIds
     */
    public function linkSelectedSources(
        AcquisitionPaperwork $paperwork,
        array $requisitionIds,
    ): void {
        $ids = collect($requisitionIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $paperwork->loadMissing('lines');

        if ($ids !== []) {
            $expected = collect($this->buildLinkedLinePayload(
                $ids,
                (int) $paperwork->item_category_id,
                (int) $paperwork->id,
            ))
                ->mapWithKeys(fn (array $line): array => [
                    (int) $line['item_id'] => (int) $line['quantity'],
                ])
                ->sortKeys()
                ->all();
            $actual = $paperwork->lines
                ->groupBy('item_id')
                ->map(fn (Collection $lines): int => (int) $lines->sum('quantity'))
                ->sortKeys()
                ->all();

            if ($expected === [] || $actual !== $expected) {
                throw ValidationException::withMessages([
                    'lines' => 'Linked requisition items changed or no longer match the requested quantities. Reselect the requisitions to refresh the PR lines.',
                ]);
            }
        }

        foreach ($paperwork->lines as $paperworkLine) {
            $paperworkLine->requisitionItems()->detach();
        }

        $paperwork->requisitions()->sync($ids);

        if ($ids === []) {
            return;
        }

        $sourceLines = $this->linkedSourceLines(
            $ids,
            (int) $paperwork->item_category_id,
            (int) $paperwork->id,
        )->groupBy('item_id');

        foreach ($paperwork->lines as $paperworkLine) {
            $remainingForPaperworkLine = (int) $paperworkLine->quantity;
            $pivotRows = [];

            foreach ($sourceLines->get($paperworkLine->item_id, collect()) as $sourceLine) {
                if ($remainingForPaperworkLine <= 0) {
                    break;
                }

                $quantity = min(
                    $remainingForPaperworkLine,
                    $this->remainingQuantityToSource($sourceLine, (int) $paperwork->id),
                );

                if ($quantity <= 0) {
                    continue;
                }

                $pivotRows[(int) $sourceLine->id] = ['quantity' => $quantity];
                $remainingForPaperworkLine -= $quantity;
            }

            if ($remainingForPaperworkLine > 0) {
                throw ValidationException::withMessages([
                    'lines' => 'A PR line exceeds the remaining quantity requested by the linked requisitions.',
                ]);
            }

            $paperworkLine->requisitionItems()->sync($pivotRows);
        }
    }

    public function linkShortcutSources(
        AcquisitionPaperwork $paperwork,
        Requisition $requisition,
        int $categoryId,
    ): void {
        if (! $this->isEligibleRequisition($requisition)) {
            throw ValidationException::withMessages([
                'linked_requisition_ids' => 'The source requisition is no longer eligible.',
            ]);
        }

        $sourceLines = $this->eligibleSourceLines($requisition, $categoryId)->groupBy('item_id');
        $paperwork->loadMissing('lines');

        foreach ($paperwork->lines as $paperworkLine) {
            $remainingForPaperworkLine = (int) $paperworkLine->quantity;
            $pivotRows = [];

            foreach ($sourceLines->get($paperworkLine->item_id, collect()) as $sourceLine) {
                if ($remainingForPaperworkLine <= 0) {
                    break;
                }

                $quantity = min($remainingForPaperworkLine, $this->remainingQuantityToSource($sourceLine));
                if ($quantity <= 0) {
                    continue;
                }

                $pivotRows[(int) $sourceLine->id] = ['quantity' => $quantity];
                $remainingForPaperworkLine -= $quantity;
            }

            if ($remainingForPaperworkLine > 0) {
                throw ValidationException::withMessages([
                    'lines' => 'A PR line exceeds the remaining eligible zero-stock quantity.',
                ]);
            }

            $paperworkLine->requisitionItems()->sync($pivotRows);
        }

        $paperwork->requisitions()->syncWithoutDetaching([$requisition->id]);
    }
}
