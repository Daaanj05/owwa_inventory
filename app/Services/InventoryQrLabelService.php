<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\InventoryUnit;
use App\Models\StockOpeningBalance;
use App\Models\StockOpeningBalanceBatch;
use App\Support\InventoryUnitQrPayload;
use App\Support\OwwaReferenceLabels;
use App\Support\UnitCostKey;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Collection;

class InventoryQrLabelService
{
    public function encodePayload(InventoryUnit|string $unitOrPropertyNumber): string
    {
        if ($unitOrPropertyNumber instanceof InventoryUnit) {
            return InventoryUnitQrPayload::encode($unitOrPropertyNumber);
        }

        return trim($unitOrPropertyNumber);
    }

    public function qrCodeDataUri(string $payload): string
    {
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'imageBase64' => true,
            'scale' => 6,
            'margin' => 1,
        ]);

        return (new QRCode($options))->render($payload);
    }

    public function supportsPaperworkQrLabels(AcquisitionPaperwork $paperwork): bool
    {
        $paperwork->loadMissing('itemCategory');

        return in_array($paperwork->itemCategory?->getTemplateSlug(), ['ppe', 'semi_expendable'], true)
            && $paperwork->isReceived();
    }

    /**
     * @return Collection<int, array<string, string|null>>
     */
    public function labelsForPaperwork(AcquisitionPaperwork $paperwork): Collection
    {
        if (! $this->supportsPaperworkQrLabels($paperwork)) {
            return collect();
        }

        $paperwork->loadMissing([
            'department',
            'acquisitions.item.category',
            'acquisitions.office',
            'acquisitions.inventoryUnits.item',
            'acquisitions.inventoryUnits.acquisition',
            'acquisitions.inventoryUnits.issuance.issuedTo',
            'acquisitions.inventoryUnits.issuance.department',
        ]);

        $labels = collect();

        foreach ($paperwork->acquisitions as $acquisition) {
            $rows = $this->labelsForAcquisition($acquisition, $paperwork);

            if ($rows->isEmpty()) {
                app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);
                $acquisition->load(['item.category', 'office', 'inventoryUnits.item', 'inventoryUnits.acquisition', 'inventoryUnits.issuance.issuedTo', 'inventoryUnits.issuance.department']);
                $rows = $this->labelsForAcquisition($acquisition, $paperwork);
            }

            $labels = $labels->concat($rows);
        }

        return $labels->values();
    }

    /**
     * @return Collection<int, array<string, string|null>>
     */
    public function labelsForAcquisition(Acquisition $acquisition, ?AcquisitionPaperwork $paperwork = null): Collection
    {
        $acquisition->loadMissing([
            'item.category',
            'office',
            'inventoryUnits.item',
            'inventoryUnits.acquisition',
            'inventoryUnits.issuance.issuedTo',
            'inventoryUnits.issuance.department',
            'acquisitionPaperwork.department',
        ]);

        $slug = $acquisition->item?->category?->getTemplateSlug();
        if (! in_array($slug, ['ppe', 'semi_expendable'], true)) {
            return collect();
        }

        $paperwork ??= $acquisition->acquisitionPaperwork;

        return $acquisition->inventoryUnits
            ->map(fn (InventoryUnit $unit) => $this->labelRowFromUnit(
                $unit,
                $acquisition->office?->name ?? '',
                $paperwork?->department?->name,
            ))
            ->values();
    }

    /**
     * Reprint of a single unit's tag (worn or lost label).
     *
     * @return Collection<int, array<string, string|null>>
     */
    public function labelsForUnit(InventoryUnit $unit): Collection
    {
        $unit->loadMissing(['office', 'item.category', 'acquisition', 'issuance.issuedTo', 'issuance.department']);

        return collect([$this->labelRowFromUnit($unit, $unit->office?->name ?? '')]);
    }

    public function supportsOpeningBalanceQrLabels(StockOpeningBalanceBatch $batch): bool
    {
        if (! $batch->isConfirmed()) {
            return false;
        }

        $batch->loadMissing('itemCategory');

        return in_array($batch->itemCategory?->getTemplateSlug(), ['ppe', 'semi_expendable'], true);
    }

    /**
     * QR labels for units minted when a confirmed opening balance was posted.
     *
     * @return Collection<int, array<string, string|null>>
     */
    public function labelsForOpeningBalanceBatch(StockOpeningBalanceBatch $batch): Collection
    {
        if (! $this->supportsOpeningBalanceQrLabels($batch)) {
            return collect();
        }

        $batch->loadMissing(['office', 'lines.item.category']);

        $labels = collect();

        foreach ($batch->lines as $line) {
            $labels = $labels->concat($this->labelsForOpeningBalanceLine($line, $batch->office?->name ?? ''));
        }

        return $labels->values();
    }

    /**
     * @return Collection<int, array<string, string|null>>
     */
    protected function labelsForOpeningBalanceLine(StockOpeningBalance $line, string $officeName): Collection
    {
        $quantity = max(0, (int) $line->quantity);
        if ($quantity < 1) {
            return collect();
        }

        $slug = $line->item?->category?->getTemplateSlug();
        if (! in_array($slug, ['ppe', 'semi_expendable'], true)) {
            return collect();
        }

        $units = InventoryUnit::query()
            ->with(['item.category', 'acquisition', 'issuance.issuedTo', 'issuance.department'])
            ->where('stock_opening_balance_id', $line->id)
            ->orderBy('id')
            ->get();

        if ($units->count() < $quantity) {
            $linkedIds = $units->pluck('id');
            $fallback = InventoryUnit::query()
                ->with(['item.category', 'acquisition', 'issuance.issuedTo', 'issuance.department'])
                ->where('item_id', $line->item_id)
                ->where('office_id', $line->office_id)
                ->whereNull('acquisition_id')
                ->whereNull('stock_opening_balance_id')
                ->where('unit_cost', UnitCostKey::normalize($line->unit_cost !== null ? (float) $line->unit_cost : null))
                ->when($linkedIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $linkedIds))
                ->orderBy('id')
                ->limit($quantity - $units->count())
                ->get();

            $units = $units->concat($fallback)->values();
        }

        return $units
            ->map(fn (InventoryUnit $unit) => $this->labelRowFromUnit($unit, $officeName))
            ->values();
    }

    /**
     * @return array<string, string|null>
     */
    protected function labelRowFromUnit(
        InventoryUnit $unit,
        string $officeName,
        ?string $departmentName = null,
    ): array {
        $unit->loadMissing(['item.category', 'acquisition', 'issuance.issuedTo', 'issuance.department']);

        $issuance = $unit->issuance;
        $department = $departmentName
            ?? $issuance?->department?->name
            ?? null;
        $cost = $unit->unit_cost ?? $unit->acquisition?->unit_cost;
        $dateAcquired = $unit->acquisition?->acquisition_date?->format('Y-m-d');

        return $this->labelRow(
            propertyNumber: (string) $unit->property_number,
            itemName: $unit->article ?? $unit->item?->name ?? 'Item',
            officeName: $officeName,
            qrPayload: InventoryUnitQrPayload::encode($unit),
            categorySlug: $unit->item?->category?->getTemplateSlug(),
            description: $unit->description ?: $unit->item?->description,
            departmentName: $department,
            endUser: $issuance?->issuedTo?->name,
            acquisitionCost: $cost !== null ? (string) $cost : null,
            dateAcquired: $dateAcquired,
        );
    }

    /**
     * @return array<string, string|null>
     */
    protected function labelRow(
        string $propertyNumber,
        string $itemName,
        string $officeName,
        string $qrPayload,
        ?string $categorySlug = null,
        ?string $description = null,
        ?string $departmentName = null,
        ?string $endUser = null,
        ?string $acquisitionCost = null,
        ?string $dateAcquired = null,
    ): array {
        $unitSection = trim($officeName.($departmentName ? ' - '.$departmentName : ''));

        return [
            'sp_tag_no' => '',
            'unit_section' => $unitSection,
            'property_number_label' => $this->propertyNumberLabel($categorySlug),
            'property_name_label' => $this->propertyNameLabel($categorySlug),
            'property_number' => $propertyNumber,
            'item_name' => $itemName,
            'description' => $description ?? '',
            'end_user' => $endUser ?? '',
            'acquisition_cost' => $acquisitionCost ?? '',
            'date_acquired' => $dateAcquired ?? '',
            'office_name' => $officeName,
            'category_slug' => $categorySlug,
            'agency_line_1' => (string) config('owwa_mail.agency_line_1', 'Republic of the Philippines'),
            'agency_line_2' => (string) config('owwa_mail.agency_line_2', 'OVERSEAS WORKERS WELFARE ADMINISTRATION'),
            'agency_address' => (string) config(
                'owwa_mail.agency_address',
                'G/F Parian Commerce Center II, National Highway, Brgy. Parian, Calamba, Laguna',
            ),
            'qr_data_uri' => $this->qrCodeDataUri($qrPayload),
        ];
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
}
