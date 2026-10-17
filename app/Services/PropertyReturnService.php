<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\PropertyActionRequest;
use App\Models\PropertyActionRequestLine;
use App\Models\Transfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PropertyReturnService
{
    /**
     * Return transfers restore exactly one unit: the linked unit, or the unit carrying the transfer's property number.
     */
    public function processReturnTransfer(Transfer $transfer): void
    {
        if ($transfer->transfer_type !== 'return') {
            return;
        }

        if (blank($transfer->property_number) && blank($transfer->inventory_unit_id)) {
            return;
        }

        DB::transaction(function () use ($transfer): void {
            $unit = InventoryUnit::query()
                ->where('item_id', $transfer->item_id)
                ->whereIn('status', [InventoryUnit::STATUS_ISSUED, InventoryUnit::STATUS_UNDER_REPAIR])
                ->when(
                    filled($transfer->inventory_unit_id),
                    fn (Builder $query): Builder => $query->whereKey($transfer->inventory_unit_id),
                    fn (Builder $query): Builder => $query->where('property_number', $transfer->property_number),
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $unit?->update([
                'status' => InventoryUnit::STATUS_IN_STOCK,
                'issuance_id' => null,
                'repair_source_issuance_id' => null,
                'office_id' => $transfer->to_office_id,
            ]);
        });
    }

    /**
     * Units an employee can still put on a property return: issued to them, custody open,
     * and not already on another open (not rejected / executed) property action request.
     *
     * @return Builder<InventoryUnit>
     */
    public function returnableUnitsQuery(int $employeeId, ?int $categoryId = null, ?int $ignoreRequestId = null): Builder
    {
        return InventoryUnit::query()
            ->where('status', InventoryUnit::STATUS_ISSUED)
            ->whereHas('issuance', function (Builder $issuance) use ($employeeId, $categoryId): void {
                $issuance
                    ->where('issued_to', $employeeId)
                    ->whereNull('custody_ended_at')
                    ->when(
                        $categoryId !== null && $categoryId > 0,
                        fn (Builder $query): Builder => $query->whereHas(
                            'item',
                            fn (Builder $item): Builder => $item->where('item_category_id', $categoryId),
                        ),
                    );
            })
            ->whereNotIn('id', $this->unitIdsOnOpenRequestsQuery($ignoreRequestId));
    }

    /**
     * @throws ValidationException
     */
    public function assertLinesReturnable(PropertyActionRequest $request): void
    {
        $request->loadMissing('lines');
        $employeeId = (int) ($request->isEmployeeRequest()
            ? $request->requested_by
            : ($request->accountable_user_id ?? $request->requested_by));

        $unitIds = $request->lines->pluck('inventory_unit_id')->filter()->map(fn (mixed $id): int => (int) $id);

        if ($unitIds->count() !== $request->lines->count()) {
            throw ValidationException::withMessages([
                'lines' => 'Each returned property must be a specific inventory unit.',
            ]);
        }

        if ($unitIds->count() !== $unitIds->unique()->count()) {
            throw ValidationException::withMessages([
                'lines' => 'Each property may only be added once.',
            ]);
        }

        $returnable = $this->returnableUnitsQuery($employeeId, ignoreRequestId: (int) $request->id)
            ->whereIn('id', $unitIds->all())
            ->count();

        if ($returnable !== $unitIds->count()) {
            throw ValidationException::withMessages([
                'lines' => 'One or more properties were already returned, disposed, or are on another open property request.',
            ]);
        }
    }

    /**
     * @return Builder<PropertyActionRequestLine>
     */
    protected function unitIdsOnOpenRequestsQuery(?int $ignoreRequestId): Builder
    {
        return PropertyActionRequestLine::query()
            ->select('inventory_unit_id')
            ->whereNotNull('inventory_unit_id')
            ->when($ignoreRequestId !== null, fn (Builder $query): Builder => $query->where('property_action_request_id', '!=', $ignoreRequestId))
            ->whereHas('propertyActionRequest', fn (Builder $request): Builder => $request
                ->whereNull('archived_at')
                ->whereNotIn('status', [
                    PropertyActionRequest::STATUS_REJECTED,
                    PropertyActionRequest::STATUS_EXECUTED,
                ]));
    }
}
