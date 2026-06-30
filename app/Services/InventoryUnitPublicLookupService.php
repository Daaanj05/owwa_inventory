<?php

namespace App\Services;

use App\Filament\Resources\Issuances\IssuanceResource;
use App\Filament\Resources\Items\ItemResource;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\User;
use App\Support\PublicAssetCardData;

class InventoryUnitPublicLookupService
{
    public function findByPropertyNumber(string $propertyNumber): ?PublicAssetCardData
    {
        $propertyNumber = trim($propertyNumber);
        if ($propertyNumber === '') {
            return null;
        }

        if (! config('inventory.qr_public_lookup', true)) {
            return null;
        }

        $unit = InventoryUnit::query()
            ->with(['item.category', 'office', 'acquisition', 'issuance'])
            ->where('property_number', $propertyNumber)
            ->first();

        if ($unit !== null) {
            return $this->fromInventoryUnit($unit);
        }

        $issuance = Issuance::query()
            ->with(['item.category', 'office'])
            ->where('property_number', $propertyNumber)
            ->first();

        if ($issuance !== null) {
            return $this->fromIssuance($issuance);
        }

        return null;
    }

    public function adminUrlFor(?InventoryUnit $unit, ?Issuance $issuance): ?string
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $user->isSupplyCustodian()) {
            return null;
        }

        $categoryId = $unit?->item?->item_category_id
            ?? $issuance?->item?->item_category_id;

        $extraParams = $categoryId ? ['category' => (string) $categoryId] : [];

        if ($issuance !== null) {
            return IssuanceResource::viewModalUrl($issuance, $extraParams);
        }

        if ($unit?->item_id !== null) {
            return ItemResource::viewModalUrl($unit->item_id, $extraParams);
        }

        return null;
    }

    protected function fromInventoryUnit(InventoryUnit $unit): PublicAssetCardData
    {
        $unitCost = $unit->acquisition?->unit_cost ?? $unit->issuance?->unit_cost;

        return new PublicAssetCardData(
            propertyNumber: (string) $unit->property_number,
            itemName: $unit->article ?? $unit->item?->name ?? 'Item',
            categoryName: $unit->item?->category?->name ?? '—',
            officeName: $unit->office?->name ?? '—',
            statusLabel: $this->statusLabel($unit->status),
            unitCostFormatted: $this->formatCost($unitCost),
            adminUrl: $this->adminUrlFor($unit, $unit->issuance),
        );
    }

    protected function fromIssuance(Issuance $issuance): PublicAssetCardData
    {
        return new PublicAssetCardData(
            propertyNumber: (string) $issuance->property_number,
            itemName: $issuance->item?->name ?? 'Item',
            categoryName: $issuance->item?->category?->name ?? '—',
            officeName: $issuance->office?->name ?? '—',
            statusLabel: 'Issued',
            unitCostFormatted: $this->formatCost($issuance->unit_cost),
            adminUrl: $this->adminUrlFor(null, $issuance),
        );
    }

    protected function statusLabel(?string $status): string
    {
        return match ($status) {
            InventoryUnit::STATUS_IN_STOCK => 'In stock',
            InventoryUnit::STATUS_ISSUED => 'Issued',
            InventoryUnit::STATUS_TRANSFERRED => 'Transferred',
            InventoryUnit::STATUS_DISPOSED => 'Disposed',
            default => ucfirst(str_replace('_', ' ', (string) $status)),
        };
    }

    protected function formatCost(mixed $unitCost): ?string
    {
        if ($unitCost === null || $unitCost === '') {
            return null;
        }

        return '₱'.number_format((float) $unitCost, 2);
    }
}
