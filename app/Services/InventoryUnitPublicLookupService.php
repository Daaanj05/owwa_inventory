<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Support\PublicAssetCardData;
use Illuminate\Support\Carbon;

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
            ->with(['item', 'office', 'acquisition', 'issuance.issuedTo', 'issuance.department'])
            ->where('property_number', $propertyNumber)
            ->first();

        if ($unit !== null) {
            return $this->fromInventoryUnit($unit);
        }

        $issuance = Issuance::query()
            ->with(['item', 'office', 'department', 'issuedTo'])
            ->where('property_number', $propertyNumber)
            ->first();

        if ($issuance !== null) {
            return $this->fromIssuance($issuance);
        }

        return null;
    }

    protected function fromInventoryUnit(InventoryUnit $unit): PublicAssetCardData
    {
        $unitCost = $unit->acquisition?->unit_cost ?? $unit->issuance?->unit_cost;
        $issuance = $unit->issuance;

        return new PublicAssetCardData(
            propertyNumber: (string) $unit->property_number,
            article: $unit->article ?? $unit->item?->name ?? '—',
            description: $unit->description ?? $unit->item?->name ?? '—',
            unitSection: $this->unitSectionLabel($unit->office?->name, $issuance?->department?->name),
            stockNumber: filled($unit->stock_number) ? (string) $unit->stock_number : '—',
            endUser: $this->endUserLabel($issuance),
            acquisitionCostFormatted: $this->formatCost($unitCost),
            dateAcquiredFormatted: $this->formatDate($unit->acquisition?->acquisition_date ?? $issuance?->issuance_date),
        );
    }

    protected function fromIssuance(Issuance $issuance): PublicAssetCardData
    {
        return new PublicAssetCardData(
            propertyNumber: (string) $issuance->property_number,
            article: $issuance->item?->name ?? '—',
            description: $issuance->item?->name ?? '—',
            unitSection: $this->unitSectionLabel($issuance->office?->name, $issuance->department?->name),
            stockNumber: '—',
            endUser: $this->endUserLabel($issuance),
            acquisitionCostFormatted: $this->formatCost($issuance->unit_cost),
            dateAcquiredFormatted: $this->formatDate($issuance->issuance_date),
        );
    }

    protected function unitSectionLabel(?string $officeName, ?string $departmentName): string
    {
        $office = filled($officeName) ? $officeName : '—';

        if (filled($departmentName)) {
            return "{$office} / {$departmentName}";
        }

        return $office;
    }

    protected function endUserLabel(?Issuance $issuance): ?string
    {
        $name = $issuance?->issuedTo?->name;

        return filled($name) ? (string) $name : null;
    }

    protected function formatCost(mixed $unitCost): ?string
    {
        if ($unitCost === null || $unitCost === '') {
            return null;
        }

        return '₱'.number_format((float) $unitCost, 2);
    }

    protected function formatDate(mixed $date): ?string
    {
        if ($date === null) {
            return null;
        }

        if ($date instanceof Carbon) {
            return $date->format('M j, Y');
        }

        return Carbon::parse($date)->format('M j, Y');
    }
}
