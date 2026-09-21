<?php

namespace App\Services;

use App\Filament\Resources\Items\Support\ItemOpeningStockFields;
use App\Models\Item;
use App\Models\StockOpeningBalanceBatch;
use App\Models\User;
use App\Support\SupplyOfficeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockOpeningBalanceBatchService
{
    public function __construct(
        protected OpeningBalanceService $openingBalanceService,
        protected SupplyOfficeResolver $supplyOfficeResolver,
        protected ReferenceCodeService $referenceCodeService,
        protected InventoryStockService $stockService,
    ) {}

    /**
     * @param  list<array{item_id: int, quantity: int, unit_cost: ?float}>  $lines
     */
    public function saveDraft(
        array $lines,
        ?string $memo,
        ?int $itemCategoryId,
        ?User $recordedBy = null,
    ): StockOpeningBalanceBatch {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one catalog item.',
            ]);
        }

        $officeId = $this->supplyOfficeResolver->resolve();
        if ($officeId === null || $officeId < 1) {
            throw ValidationException::withMessages([
                'office' => 'Regional supply office is not configured.',
            ]);
        }

        return DB::transaction(function () use ($lines, $memo, $itemCategoryId, $recordedBy, $officeId): StockOpeningBalanceBatch {
            $batch = StockOpeningBalanceBatch::query()->create([
                'office_id' => $officeId,
                'item_category_id' => $itemCategoryId,
                'reference_code' => $this->referenceCodeService->forOpeningBalance(),
                'reference' => filled($memo) ? trim($memo) : null,
                'recorded_on' => null,
                'recorded_by' => $recordedBy?->id,
                'recorded_at' => now(),
                'confirmed_at' => null,
            ]);

            $this->persistDraftLines($batch, $lines, $officeId, $recordedBy);

            return $batch->loadCount('lines');
        });
    }

    /**
     * @param  list<array{item_id: int, quantity: int, unit_cost: ?float}>  $lines
     */
    public function updateDraft(
        StockOpeningBalanceBatch $batch,
        array $lines,
        ?string $memo,
        ?User $recordedBy = null,
    ): StockOpeningBalanceBatch {
        if (! $batch->isDraft()) {
            throw ValidationException::withMessages([
                'batch' => 'Only draft opening balances can be edited.',
            ]);
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one catalog item.',
            ]);
        }

        return DB::transaction(function () use ($batch, $lines, $memo, $recordedBy): StockOpeningBalanceBatch {
            $previousItemIds = $batch->lines()->pluck('item_id')->map(fn ($id): int => (int) $id)->all();
            $batch->lines()->delete();

            $batch->forceFill([
                'reference' => filled($memo) ? trim($memo) : null,
                'recorded_by' => $recordedBy?->id ?? $batch->recorded_by,
                'recorded_at' => now(),
            ])->save();

            $this->persistDraftLines($batch, $lines, (int) $batch->office_id, $recordedBy, $previousItemIds);

            return $batch->fresh()->loadCount('lines');
        });
    }

    public function deleteDraft(StockOpeningBalanceBatch $batch): void
    {
        if (! $batch->isDraft()) {
            throw ValidationException::withMessages([
                'batch' => 'Only draft opening balances can be deleted.',
            ]);
        }

        DB::transaction(function () use ($batch): void {
            $batch->lines()->delete();
            $batch->delete();
        });
    }

    public function confirm(StockOpeningBalanceBatch $batch): StockOpeningBalanceBatch
    {
        if ($batch->isConfirmed()) {
            throw ValidationException::withMessages([
                'batch' => 'This opening balance is already confirmed.',
            ]);
        }

        return DB::transaction(function () use ($batch): StockOpeningBalanceBatch {
            $batch->load('lines.item.category');

            foreach ($batch->lines as $line) {
                $this->openingBalanceService->mintUnitsForConfirmedLine($line);
            }

            $batch->forceFill([
                'confirmed_at' => now(),
                'recorded_on' => now()->toDateString(),
                'recorded_at' => now(),
            ])->save();

            $this->stockService->forgetMovementTotalsCache();

            return $batch->fresh()->loadCount('lines');
        });
    }

    /**
     * @deprecated Use saveDraft() then confirm(). Kept for callers that still need immediate post.
     *
     * @param  list<array{item_id: int, quantity: int, unit_cost: ?float}>  $lines
     */
    public function record(
        array $lines,
        ?string $reference,
        string $recordedOn,
        ?int $itemCategoryId,
        ?User $recordedBy = null,
    ): StockOpeningBalanceBatch {
        $batch = $this->saveDraft($lines, $reference, $itemCategoryId, $recordedBy);
        $confirmed = $this->confirm($batch);
        $confirmed->forceFill(['recorded_on' => $recordedOn])->save();

        return $confirmed->fresh()->loadCount('lines');
    }

    /**
     * @param  list<array{item_id: int, quantity: int, unit_cost: ?float}>  $lines
     * @param  list<int>  $allowItemIds  Item IDs already on this batch before an update (re-allowed after line delete).
     */
    protected function persistDraftLines(
        StockOpeningBalanceBatch $batch,
        array $lines,
        int $officeId,
        ?User $recordedBy,
        array $allowItemIds = [],
    ): void {
        $allowItemIds = array_values(array_unique(array_map('intval', $allowItemIds)));

        foreach ($lines as $index => $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);
            $unitCost = array_key_exists('unit_cost', $line) && $line['unit_cost'] !== null && $line['unit_cost'] !== ''
                ? (float) $line['unit_cost']
                : null;

            $item = Item::query()->find($itemId);
            if ($item === null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.item_id" => 'Select a valid catalog item.',
                ]);
            }

            $isAllowedExisting = in_array($itemId, $allowItemIds, true);
            if (! $isAllowedExisting && ! ItemOpeningStockFields::canSetStartingStock($item, $officeId)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.item_id" => 'This item already has opening stock or acquisition history at the regional supply office.',
                ]);
            }

            try {
                $this->openingBalanceService->createDraftLine(
                    item: $item,
                    officeId: $officeId,
                    quantity: $quantity,
                    unitCost: $unitCost,
                    recordedBy: $recordedBy,
                    batchId: (int) $batch->id,
                );
            } catch (ValidationException $exception) {
                $messages = collect($exception->errors())->flatten()->all();
                throw ValidationException::withMessages([
                    "lines.{$index}.quantity" => $messages[0] ?? 'Unable to record opening stock for this line.',
                ]);
            }
        }
    }
}
