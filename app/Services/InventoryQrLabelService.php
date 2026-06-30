<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Support\InventoryUnitQrPayload;
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
     * @return Collection<int, array{property_number: string, item_name: string, office_name: string, qr_data_uri: string}>
     */
    public function labelsForPaperwork(AcquisitionPaperwork $paperwork): Collection
    {
        if (! $this->supportsPaperworkQrLabels($paperwork)) {
            return collect();
        }

        $paperwork->loadMissing([
            'acquisitions.item.category',
            'acquisitions.office',
            'acquisitions.inventoryUnits',
        ]);

        $labels = collect();

        foreach ($paperwork->acquisitions as $acquisition) {
            $rows = $this->labelsForAcquisition($acquisition);

            if ($rows->isEmpty()) {
                app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);
                $acquisition->load(['item.category', 'office', 'inventoryUnits']);
                $rows = $this->labelsForAcquisition($acquisition);
            }

            $labels = $labels->concat($rows);
        }

        return $labels->values();
    }

    /**
     * @return Collection<int, array{property_number: string, item_name: string, office_name: string, qr_data_uri: string}>
     */
    public function labelsForAcquisition(Acquisition $acquisition): Collection
    {
        $acquisition->loadMissing(['item.category', 'office', 'inventoryUnits']);

        $slug = $acquisition->item?->category?->getTemplateSlug();
        if (! in_array($slug, ['ppe', 'semi_expendable'], true)) {
            return collect();
        }

        return $acquisition->inventoryUnits
            ->map(fn (InventoryUnit $unit) => $this->labelRowFromUnit($unit, $acquisition->office?->name ?? ''))
            ->values();
    }

    /**
     * @return Collection<int, array{property_number: string, item_name: string, office_name: string, qr_data_uri: string}>
     */
    public function labelsForIssuance(Issuance $issuance): Collection
    {
        $issuance->loadMissing(['item', 'office', 'inventoryUnit']);

        if ($issuance->inventoryUnit !== null) {
            return collect([
                $this->labelRowFromUnit($issuance->inventoryUnit, $issuance->office?->name ?? ''),
            ]);
        }

        if (blank($issuance->property_number)) {
            return collect();
        }

        return collect([
            $this->labelRow(
                (string) $issuance->property_number,
                $issuance->item?->name ?? 'Item',
                $issuance->office?->name ?? '',
            ),
        ]);
    }

    /**
     * @return Collection<int, array{property_number: string, item_name: string, office_name: string, qr_data_uri: string}>
     */
    public function labelsForSession(PhysicalCountSession $session): Collection
    {
        $session->loadMissing(['office', 'lines.item']);

        return $session->lines
            ->filter(fn (PhysicalCountLine $line): bool => filled($line->property_number))
            ->map(fn (PhysicalCountLine $line) => $this->labelRow(
                (string) $line->property_number,
                $line->article ?? $line->item?->name ?? 'Item',
                $session->office?->name ?? '',
            ))
            ->values();
    }

    /**
     * @return array{property_number: string, item_name: string, office_name: string, qr_data_uri: string}
     */
    protected function labelRowFromUnit(InventoryUnit $unit, string $officeName): array
    {
        $payload = InventoryUnitQrPayload::encode($unit);

        return [
            'property_number' => $unit->property_number,
            'item_name' => $unit->article ?? $unit->item?->name ?? 'Item',
            'office_name' => $officeName,
            'qr_data_uri' => $this->qrCodeDataUri($payload),
        ];
    }

    /**
     * @return array{property_number: string, item_name: string, office_name: string, qr_data_uri: string}
     */
    protected function labelRow(string $propertyNumber, string $itemName, string $officeName): array
    {
        $unit = InventoryUnit::query()->where('property_number', $propertyNumber)->first();

        $payload = $unit !== null
            ? InventoryUnitQrPayload::encode($unit)
            : (config('inventory.qr_public_lookup', true)
                ? InventoryUnitQrPayload::publicUrl($propertyNumber)
                : $propertyNumber);

        return [
            'property_number' => $propertyNumber,
            'item_name' => $itemName,
            'office_name' => $officeName,
            'qr_data_uri' => $this->qrCodeDataUri($payload),
        ];
    }
}
