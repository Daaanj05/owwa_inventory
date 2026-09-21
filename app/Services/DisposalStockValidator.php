<?php

namespace App\Services;

use App\Models\Disposal;
use App\Models\InventoryUnit;
use App\Support\CustodianOfficeScope;
use App\Support\SupplyOfficeResolver;
use Illuminate\Validation\ValidationException;

class DisposalStockValidator
{
    public function __construct(
        private InventoryStockService $stockService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function validateForCreate(array $data): void
    {
        $this->validateCommon($data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function validateForUpdate(array $data, Disposal $existing): void
    {
        $this->validateCommon($data, $existing);
    }

    /**
     * @throws ValidationException
     */
    public function validateRecord(Disposal $disposal): void
    {
        $this->validateCommon([
            'item_id' => $disposal->item_id,
            'office_id' => $disposal->office_id,
            'quantity' => $disposal->quantity,
            'inventory_unit_id' => $disposal->inventory_unit_id,
            'disposal_type' => $disposal->disposal_type,
        ], $disposal);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function validateCommon(array $data, ?Disposal $existing = null): void
    {
        $itemId = (int) ($data['item_id'] ?? 0);
        $quantity = (int) ($data['quantity'] ?? 0);
        $inventoryUnitId = filled($data['inventory_unit_id'] ?? null)
            ? (int) $data['inventory_unit_id']
            : null;

        $officeId = filled($data['office_id'] ?? null) ? (int) $data['office_id'] : null;

        $disposalType = (string) ($data['disposal_type'] ?? '');
        $isConsumableWmr = $disposalType === 'waste_sale'
            || ($existing?->item?->category?->getTemplateSlug() === 'consumables');

        if ($isConsumableWmr) {
            $officeId = app(SupplyOfficeResolver::class)->resolve() ?? $officeId;
        }

        if ($itemId <= 0) {
            throw ValidationException::withMessages([
                'item_id' => 'Select an item.',
            ]);
        }

        if ($officeId === null || $officeId <= 0) {
            throw ValidationException::withMessages([
                'office_id' => 'Select an office.',
            ]);
        }

        CustodianOfficeScope::assertOfficeAllowed($officeId);

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be at least 1.',
            ]);
        }

        if ($inventoryUnitId !== null) {
            $this->validateInventoryUnit($inventoryUnitId, $itemId, $officeId, $quantity, $existing);

            return;
        }

        $available = $this->stockService->getStock($itemId, $officeId);

        if ($existing !== null
            && (int) $existing->item_id === $itemId
            && (int) $existing->office_id === $officeId
            && blank($existing->inventory_unit_id)) {
            $available += (int) $existing->quantity;
        }

        if ($quantity > $available) {
            throw ValidationException::withMessages([
                'quantity' => "Quantity exceeds stock on hand ({$available}).",
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function validateInventoryUnit(
        int $inventoryUnitId,
        int $itemId,
        int $officeId,
        int $quantity,
        ?Disposal $existing = null,
    ): void {
        if ($quantity !== 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be 1 when a specific inventory unit is selected.',
            ]);
        }

        $unit = InventoryUnit::query()->find($inventoryUnitId);

        if ($unit === null) {
            throw ValidationException::withMessages([
                'inventory_unit_id' => 'The selected inventory unit was not found.',
            ]);
        }

        if ((int) $unit->item_id !== $itemId) {
            throw ValidationException::withMessages([
                'inventory_unit_id' => 'The inventory unit does not match the selected item.',
            ]);
        }

        if ((int) $unit->office_id !== $officeId) {
            throw ValidationException::withMessages([
                'inventory_unit_id' => 'The inventory unit does not match the selected office.',
            ]);
        }

        $allowedStatuses = [InventoryUnit::STATUS_IN_STOCK, InventoryUnit::STATUS_ISSUED];
        $isSameUnitOnExisting = $existing !== null
            && (int) $existing->inventory_unit_id === $inventoryUnitId;

        if ($unit->status === InventoryUnit::STATUS_DISPOSED && ! $isSameUnitOnExisting) {
            throw ValidationException::withMessages([
                'inventory_unit_id' => 'This inventory unit has already been disposed.',
            ]);
        }

        if (! in_array($unit->status, $allowedStatuses, true) && ! $isSameUnitOnExisting) {
            throw ValidationException::withMessages([
                'inventory_unit_id' => 'This inventory unit is not available for disposal.',
            ]);
        }

        $claimed = Disposal::query()
            ->where('inventory_unit_id', $inventoryUnitId)
            ->when(
                $existing !== null,
                fn ($query) => $query->where('id', '!=', $existing->id),
            )
            ->exists();

        if ($claimed) {
            throw ValidationException::withMessages([
                'inventory_unit_id' => 'This inventory unit is already linked to another disposal or incident report.',
            ]);
        }
    }
}
