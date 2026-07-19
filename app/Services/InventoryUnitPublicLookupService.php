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
            ->with(['item', 'office', 'acquisition', 'issuance.department'])
            ->where('property_number', $propertyNumber)
            ->first();

        if ($unit !== null) {
            return $this->fromInventoryUnit($unit);
        }

        $issuance = Issuance::query()
            ->with(['item', 'office', 'department'])
            ->where('property_number', $propertyNumber)
            ->first();

        if ($issuance !== null) {
            return $this->fromIssuance($issuance);
        }

        return null;
    }

    protected function fromInventoryUnit(InventoryUnit $unit): PublicAssetCardData
    {
        $issuance = $unit->issuance;

        return new PublicAssetCardData(
            propertyNumber: (string) $unit->property_number,
            article: $unit->article ?? $unit->item?->name ?? '—',
            description: $unit->description ?? $unit->item?->name ?? '—',
            unitSection: $this->unitSectionLabel($unit->office?->name, $issuance?->department?->name),
            stockNumber: filled($unit->stock_number) ? (string) $unit->stock_number : '—',
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
