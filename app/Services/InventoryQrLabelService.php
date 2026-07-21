<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Support\InventoryUnitQrPayload;
use App\Support\OwwaReferenceLabels;
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
     * @return Collection<int, array<string, string|null>>
     */
    public function labelsForIssuance(Issuance $issuance): Collection
    {
        $issuance->loadMissing([
            'item.category',
            'office',
            'department',
            'issuedTo',
            'inventoryUnit.item',
            'inventoryUnit.acquisition',
            'inventoryUnit.issuance.issuedTo',
            'inventoryUnit.issuance.department',
        ]);

        if ($issuance->inventoryUnit !== null) {
            return collect([
                $this->labelRowFromUnit(
                    $issuance->inventoryUnit,
                    $issuance->office?->name ?? '',
                    $issuance->department?->name,
                    $issuance,
                ),
            ]);
        }

        if (blank($issuance->property_number)) {
            return collect();
        }

        return collect([
            $this->labelRow(
                propertyNumber: (string) $issuance->property_number,
                itemName: $issuance->item?->name ?? 'Item',
                officeName: $issuance->office?->name ?? '',
                categorySlug: $issuance->item?->category?->getTemplateSlug(),
                description: $issuance->item?->description,
                departmentName: $issuance->department?->name,
                endUser: $issuance->issuedTo?->name,
                acquisitionCost: null,
                dateAcquired: null,
            ),
        ]);
    }

    /**
     * @return Collection<int, array<string, string|null>>
     */
    public function labelsForSession(PhysicalCountSession $session): Collection
    {
        $session->loadMissing(['office', 'lines.item.category']);

        return $session->lines
            ->filter(fn (PhysicalCountLine $line): bool => filled($line->property_number))
            ->map(function (PhysicalCountLine $line) use ($session): array {
                $unit = InventoryUnit::query()
                    ->with(['item.category', 'acquisition', 'issuance.issuedTo', 'issuance.department'])
                    ->where('property_number', $line->property_number)
                    ->first();

                if ($unit !== null) {
                    return $this->labelRowFromUnit($unit, $session->office?->name ?? '');
                }

                return $this->labelRow(
                    propertyNumber: (string) $line->property_number,
                    itemName: $line->article ?? $line->item?->name ?? 'Item',
                    officeName: $session->office?->name ?? '',
                    categorySlug: $line->item?->category?->getTemplateSlug(),
                    description: $line->item?->description,
                );
            })
            ->values();
    }

    /**
     * @return array<string, string|null>
     */
    protected function labelRowFromUnit(
        InventoryUnit $unit,
        string $officeName,
        ?string $departmentName = null,
        ?Issuance $issuanceOverride = null,
    ): array {
        $unit->loadMissing(['item.category', 'acquisition', 'issuance.issuedTo', 'issuance.department']);

        $issuance = $issuanceOverride ?? $unit->issuance;
        $department = $departmentName
            ?? $issuance?->department?->name
            ?? null;
        $cost = $unit->unit_cost ?? $unit->acquisition?->unit_cost;
        $dateAcquired = $unit->acquisition?->acquisition_date?->format('Y-m-d');

        return $this->labelRow(
            propertyNumber: (string) $unit->property_number,
            itemName: $unit->article ?? $unit->item?->name ?? 'Item',
            officeName: $officeName,
            categorySlug: $unit->item?->category?->getTemplateSlug(),
            description: $unit->description ?: $unit->item?->description,
            departmentName: $department,
            endUser: $issuance?->issuedTo?->name,
            acquisitionCost: $cost !== null ? (string) $cost : null,
            dateAcquired: $dateAcquired,
            qrPayload: InventoryUnitQrPayload::encode($unit),
        );
    }

    /**
     * @return array<string, string|null>
     */
    protected function labelRow(
        string $propertyNumber,
        string $itemName,
        string $officeName,
        ?string $categorySlug = null,
        ?string $description = null,
        ?string $departmentName = null,
        ?string $endUser = null,
        ?string $acquisitionCost = null,
        ?string $dateAcquired = null,
        ?string $qrPayload = null,
    ): array {
        if ($qrPayload === null) {
            $unit = InventoryUnit::query()->where('property_number', $propertyNumber)->first();
            $qrPayload = $unit !== null
                ? InventoryUnitQrPayload::encode($unit)
                : (config('inventory.qr_public_lookup', true)
                    ? InventoryUnitQrPayload::publicUrl($propertyNumber)
                    : $propertyNumber);
        }

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
