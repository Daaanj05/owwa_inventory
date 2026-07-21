<?php

namespace App\Services;

use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Support\OwwaReferenceLabels;
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
            ->with(['item.category', 'office', 'acquisition', 'issuance.department', 'issuance.issuedTo'])
            ->where('property_number', $propertyNumber)
            ->first();

        if ($unit !== null) {
            return $this->fromInventoryUnit($unit);
        }

        $issuance = Issuance::query()
            ->with(['item.category', 'office', 'department', 'issuedTo'])
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
        $slug = $unit->item?->category?->getTemplateSlug();
        $cost = $unit->unit_cost ?? $unit->acquisition?->unit_cost;
        $date = $unit->acquisition?->acquisition_date ?? $issuance?->issuance_date;

        return new PublicAssetCardData(
            propertyNumber: (string) $unit->property_number,
            article: $unit->article ?? $unit->item?->name ?? '—',
            description: filled($unit->description)
                ? (string) $unit->description
                : (string) ($unit->item?->description ?? ''),
            unitSection: $this->unitSectionLabel($unit->office?->name, $issuance?->department?->name),
            stockNumber: filled($unit->stock_number) ? (string) $unit->stock_number : '—',
            dateAcquiredFormatted: $this->formatDate($date),
            agencyLine1: (string) config('owwa_mail.agency_line_1', 'Republic of the Philippines'),
            agencyLine2: (string) config('owwa_mail.agency_line_2', 'OVERSEAS WORKERS WELFARE ADMINISTRATION'),
            agencyAddress: (string) config(
                'owwa_mail.agency_address',
                'G/F Parian Commerce Center II, National Highway, Brgy. Parian, Calamba, Laguna',
            ),
            spTagNo: '',
            propertyNumberLabel: $this->propertyNumberLabel($slug),
            propertyNameLabel: $this->propertyNameLabel($slug),
            endUser: (string) ($issuance?->issuedTo?->name ?? ''),
            acquisitionCost: $cost !== null ? '₱'.number_format((float) $cost, 2) : '',
        );
    }

    protected function fromIssuance(Issuance $issuance): PublicAssetCardData
    {
        $slug = $issuance->item?->category?->getTemplateSlug();

        return new PublicAssetCardData(
            propertyNumber: (string) $issuance->property_number,
            article: $issuance->item?->name ?? '—',
            description: (string) ($issuance->item?->description ?? ''),
            unitSection: $this->unitSectionLabel($issuance->office?->name, $issuance->department?->name),
            stockNumber: '—',
            dateAcquiredFormatted: $this->formatDate($issuance->issuance_date),
            agencyLine1: (string) config('owwa_mail.agency_line_1', 'Republic of the Philippines'),
            agencyLine2: (string) config('owwa_mail.agency_line_2', 'OVERSEAS WORKERS WELFARE ADMINISTRATION'),
            agencyAddress: (string) config(
                'owwa_mail.agency_address',
                'G/F Parian Commerce Center II, National Highway, Brgy. Parian, Calamba, Laguna',
            ),
            spTagNo: '',
            propertyNumberLabel: $this->propertyNumberLabel($slug),
            propertyNameLabel: $this->propertyNameLabel($slug),
            endUser: (string) ($issuance->issuedTo?->name ?? ''),
            acquisitionCost: $issuance->unit_cost !== null
                ? '₱'.number_format((float) $issuance->unit_cost, 2)
                : '',
        );
    }

    protected function unitSectionLabel(?string $officeName, ?string $departmentName): string
    {
        $office = filled($officeName) ? $officeName : '—';

        if (filled($departmentName)) {
            return "{$office} - {$departmentName}";
        }

        return $office;
    }

    protected function propertyNumberLabel(?string $categorySlug): string
    {
        return match ($categorySlug) {
            'ppe' => OwwaReferenceLabels::PROPERTY_NO,
            'semi_expendable' => 'Semi-Expendable Property no.',
            default => OwwaReferenceLabels::assetIdentifierLabel($categorySlug),
        };
    }

    protected function propertyNameLabel(?string $categorySlug): string
    {
        return match ($categorySlug) {
            'ppe' => 'Property',
            'semi_expendable' => 'Semi-Expendable Property',
            default => 'Property',
        };
    }

    protected function formatDate(mixed $date): ?string
    {
        if ($date === null) {
            return null;
        }

        if ($date instanceof Carbon) {
            return $date->format('Y-m-d');
        }

        return Carbon::parse($date)->format('Y-m-d');
    }
}
