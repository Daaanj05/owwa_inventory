<?php

namespace App\Services;

use App\Models\Transfer;
use App\Models\User;
use App\Support\SupplyOfficeResolver;
use App\Support\UnitCostKey;
use Illuminate\Validation\ValidationException;

class TransferStockValidator
{
    public function __construct(
        private InventoryStockService $stockService,
        private TransferItemOptionsService $itemOptions,
        private SupplyOfficeResolver $supplyOfficeResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function validateForCreate(array $data, ?User $user = null): void
    {
        $this->validateCommon($data, $user);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function validateForUpdate(array $data, Transfer $existing, ?User $user = null): void
    {
        $this->validateCommon($data, $user, $existing);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function validateCommon(array $data, ?User $user = null, ?Transfer $existing = null): void
    {
        $fromOfficeId = (int) ($data['from_office_id'] ?? 0);
        $toOfficeId = (int) ($data['to_office_id'] ?? 0);
        $itemId = (int) ($data['item_id'] ?? 0);
        $quantity = (int) ($data['quantity'] ?? 0);
        $type = (string) ($data['transfer_type'] ?? '');
        $unitCost = isset($data['unit_cost']) && $data['unit_cost'] !== '' && $data['unit_cost'] !== null
            ? (float) $data['unit_cost']
            : null;

        if ($fromOfficeId <= 0) {
            throw ValidationException::withMessages([
                'from_office_id' => 'Select the source office.',
            ]);
        }

        if (! array_key_exists($type, Transfer::typeOptions())) {
            throw ValidationException::withMessages([
                'transfer_type' => 'Select a transfer type.',
            ]);
        }

        if ($type === Transfer::TYPE_OTHERS && blank($data['transfer_type_other'] ?? null)) {
            throw ValidationException::withMessages([
                'transfer_type_other' => 'Specify the reason for Others.',
            ]);
        }

        if ($type === Transfer::TYPE_RETURN) {
            $this->validateReturn($data, $fromOfficeId, $toOfficeId, $itemId, $quantity, $existing);

            return;
        }

        if ($toOfficeId <= 0) {
            throw ValidationException::withMessages([
                'to_office_id' => 'Select the destination office.',
            ]);
        }

        if ($fromOfficeId === $toOfficeId) {
            throw ValidationException::withMessages([
                'to_office_id' => 'Destination office must be different from the source office.',
            ]);
        }

        if ($itemId <= 0) {
            throw ValidationException::withMessages([
                'item_id' => 'Select an item to transfer.',
            ]);
        }

        if (! $this->stockService->hasInventoryActivity($itemId, $fromOfficeId)) {
            throw ValidationException::withMessages([
                'item_id' => 'This item has no inventory history at the source office.',
            ]);
        }

        $buckets = $this->stockService->getUnitCostBucketsWithStock($itemId, $fromOfficeId);
        if (count($buckets) > 1 && $unitCost === null) {
            throw ValidationException::withMessages([
                'unit_cost' => 'Select the unit cost bucket to transfer from.',
            ]);
        }

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be at least 1.',
            ]);
        }

        $available = $unitCost !== null
            ? $this->stockService->getStockForUnitCost($itemId, $fromOfficeId, $unitCost)
            : $this->stockService->getStock($itemId, $fromOfficeId);

        if ($existing !== null
            && (int) $existing->item_id === $itemId
            && (int) $existing->from_office_id === $fromOfficeId
            && ($existing->transfer_type ?? null) !== Transfer::TYPE_RETURN
            && UnitCostKey::equals(
                $existing->unit_cost !== null ? (float) $existing->unit_cost : null,
                $unitCost,
            )) {
            $available += (int) $existing->quantity;
        }

        if ($quantity > $available) {
            throw ValidationException::withMessages([
                'quantity' => "Insufficient stock at the source office. Maximum available: {$available}.",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function validateReturn(
        array $data,
        int $fromOfficeId,
        int $toOfficeId,
        int $itemId,
        int $quantity,
        ?Transfer $existing,
    ): void {
        $regionalOfficeId = $this->supplyOfficeResolver->resolve();

        if ($regionalOfficeId === null) {
            throw ValidationException::withMessages([
                'to_office_id' => 'Regional office is not configured. Return to stock cannot be saved.',
            ]);
        }

        if ($toOfficeId !== $regionalOfficeId) {
            throw ValidationException::withMessages([
                'to_office_id' => 'Return to stock must go to the regional office.',
            ]);
        }

        if ($itemId <= 0) {
            throw ValidationException::withMessages([
                'item_id' => 'Select an issued item to return.',
            ]);
        }

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be at least 1.',
            ]);
        }

        $propertyNumber = filled($data['property_number'] ?? null) ? (string) $data['property_number'] : null;
        $issued = $this->itemOptions->stillIssuedQuantity($itemId, $fromOfficeId, $propertyNumber, $existing);

        if ($quantity > $issued) {
            throw ValidationException::withMessages([
                'quantity' => "Quantity is higher than what is still issued for this item. Maximum: {$issued}.",
            ]);
        }
    }
}
