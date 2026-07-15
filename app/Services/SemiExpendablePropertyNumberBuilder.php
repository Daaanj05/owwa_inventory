<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemStockBucket;
use App\Models\PropertyNumberBucket;
use App\Models\StockPositionRestockFlag;
use App\Support\ItemPropertyClass;
use App\Support\SemiExpendableValueCategory;
use App\Support\UnitCostKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SemiExpendablePropertyNumberBuilder
{
    /**
     * @return array<string, string>
     */
    public function previewSegments(Issuance $issuance, ?Item $item = null): array
    {
        $item ??= $this->resolveItem($issuance);
        $unitCost = $this->resolveUnitCost($issuance, $item);
        $acqYear = $this->resolveAcquisitionYear($issuance, $item);

        return [
            'value_category' => SemiExpendableValueCategory::prefixForUnitCost($unitCost),
            'acq_year' => (string) $acqYear,
            'supply_type_code' => ItemPropertyClass::supplyTypeCode($item?->property_class),
            'uacs_prefix' => ItemPropertyClass::uacsPrefix($item?->property_class),
            'custodian_code' => $this->resolveCustodianCode($issuance),
        ];
    }

    public function previewNext(Issuance $issuance, ?Item $item = null): string
    {
        $item ??= $this->resolveItem($issuance);
        $unitCost = $this->resolveUnitCost($issuance, $item);

        if ($item !== null && $issuance->office_id !== null) {
            $existing = $this->resolveExistingPropertyNumberForItem($item);
            if ($existing !== null) {
                return $existing;
            }

            return $this->previewNextForAcquisition(
                (int) $issuance->office_id,
                $item,
                $unitCost,
                $issuance->issuance_date,
            );
        }

        $segments = $this->previewSegmentsForOffice(
            (int) ($issuance->office_id ?? 0),
            $item,
            $unitCost,
            (int) ($issuance->issuance_date?->format('Y') ?? now()->format('Y')),
        );
        $bucketKey = $this->bucketKey($segments);
        $sequence = PropertyNumberBucket::query()
            ->where('bucket_key', $bucketKey)
            ->value('next_sequence') ?? 1;

        return $this->formatNumber($segments, (int) $sequence);
    }

    public function previewNextForAcquisition(
        int $officeId,
        Item $item,
        ?float $unitCost,
        ?\DateTimeInterface $acquisitionDate = null,
    ): string {
        $existing = $this->resolveExistingPropertyNumberForItem($item);
        if ($existing !== null) {
            return $existing;
        }

        $segments = $this->previewSegmentsForOffice(
            $officeId,
            $item,
            $unitCost,
            (int) ($acquisitionDate?->format('Y') ?? now()->format('Y')),
        );
        $bucketKey = $this->bucketKey($segments);
        $sequence = PropertyNumberBucket::query()
            ->where('bucket_key', $bucketKey)
            ->value('next_sequence') ?? 1;

        return $this->formatNumber($segments, (int) $sequence);
    }

    public function assignForIssuance(Issuance $issuance): string
    {
        return $this->resolveExistingForIssuance($issuance);
    }

    /**
     * @throws ValidationException
     */
    public function resolveExistingForIssuance(Issuance $issuance): string
    {
        $item = $this->resolveItem($issuance);

        if ($item !== null) {
            $existingForItem = $this->resolveExistingPropertyNumberForItem($item);
            if ($existingForItem !== null) {
                return $existingForItem;
            }
        }

        throw ValidationException::withMessages([
            'property_number' => 'No inventory item number assigned for this item. Record acquisition first.',
        ]);
    }

    public function resolveExistingPropertyNumberForItem(Item $item): ?string
    {
        if (filled($item->semi_expendable_property_number)) {
            return (string) $item->semi_expendable_property_number;
        }

        $fromBucket = ItemStockBucket::query()
            ->where('item_id', $item->id)
            ->whereNotNull('property_number')
            ->orderBy('id')
            ->value('property_number');

        if (filled($fromBucket)) {
            return (string) $fromBucket;
        }

        $fromIssuance = Issuance::query()
            ->where('item_id', $item->id)
            ->whereNotNull('property_number')
            ->orderBy('issuance_date')
            ->orderBy('id')
            ->value('property_number');

        return filled($fromIssuance) ? (string) $fromIssuance : null;
    }

    /**
     * @deprecated Use resolveExistingForIssuance() or resolveOrAssignForAcquisition() only.
     *
     * @throws ValidationException
     */
    public function resolveOrAssignForIssuance(Issuance $issuance): string
    {
        return $this->resolveExistingForIssuance($issuance);
    }

    public function resolveOrAssignForAcquisition(Acquisition $acquisition): string
    {
        $item = $this->resolveAcquisitionItem($acquisition);
        $unitCost = $acquisition->unit_cost !== null ? (float) $acquisition->unit_cost : null;

        if ($item === null) {
            throw ValidationException::withMessages([
                'property_number' => 'Item is required to assign an inventory item number.',
            ]);
        }

        $number = app(CatalogAssetNumberService::class)->finalizeSemiWithUnitCost($item, $unitCost);
        $this->persistBucketPropertyNumber($item, $unitCost, $number);
        StockPositionRestockFlag::reactivateOnAcquisition(
            (int) $item->id,
            (int) $acquisition->office_id,
            $unitCost,
        );

        return $number;
    }

    public function assignForAcquisition(Acquisition $acquisition): string
    {
        return $this->resolveOrAssignForAcquisition($acquisition);
    }

    /**
     * @return array<string, string>
     */
    public function previewSegmentsForAcquisition(Acquisition $acquisition, ?Item $item = null): array
    {
        $item ??= $this->resolveAcquisitionItem($acquisition);
        $unitCost = $acquisition->unit_cost !== null ? (float) $acquisition->unit_cost : null;
        $acqYear = (int) ($acquisition->acquisition_date?->format('Y') ?? now()->format('Y'));

        return $this->previewSegmentsForOffice(
            (int) ($acquisition->office_id ?? 0),
            $item,
            $unitCost,
            $acqYear,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function resolveOfficeCustodianCode(Acquisition $acquisition): string
    {
        $segments = $this->previewSegmentsForOffice(
            (int) ($acquisition->office_id ?? 0),
            $this->resolveAcquisitionItem($acquisition),
            $acquisition->unit_cost !== null ? (float) $acquisition->unit_cost : null,
            (int) ($acquisition->acquisition_date?->format('Y') ?? now()->format('Y')),
        );

        return $segments['custodian_code'];
    }

    public function persistBucketPropertyNumber(Item $item, ?float $unitCost, string $number): void
    {
        if (blank($item->semi_expendable_property_number) || str_starts_with((string) $item->semi_expendable_property_number, 'TEMP-')) {
            $item->forceFill(['semi_expendable_property_number' => $number])->save();
        }

        ItemStockBucket::query()
            ->where('item_id', $item->id)
            ->where(function ($query) use ($number): void {
                $query->whereNull('property_number')
                    ->orWhere('property_number', '!=', $number)
                    ->orWhere('property_number', 'like', 'TEMP-%');
            })
            ->update(['property_number' => $number]);

        $bucket = ItemStockBucket::firstOrCreateForItemCost((int) $item->id, $unitCost);
        if (blank($bucket->property_number) || str_starts_with((string) $bucket->property_number, 'TEMP-')) {
            $bucket->update(['property_number' => $number]);
        }
    }

    protected function resolveAcquisitionItem(Acquisition $acquisition): ?Item
    {
        if ($acquisition->relationLoaded('item')) {
            return $acquisition->item;
        }

        return Item::query()->with('category')->find($acquisition->item_id);
    }

    /**
     * @return array<string, string>
     */
    public function previewSegmentsForOffice(
        int $officeId,
        ?Item $item,
        ?float $unitCost,
        ?int $acqYear = null,
    ): array {
        $office = $officeId > 0 ? \App\Models\Office::query()->find($officeId) : null;
        $code = trim((string) ($office?->code ?? ''));

        return [
            'value_category' => SemiExpendableValueCategory::prefixForUnitCost($unitCost),
            'acq_year' => (string) ($acqYear ?? now()->format('Y')),
            'supply_type_code' => ItemPropertyClass::supplyTypeCode($item?->property_class),
            'uacs_prefix' => ItemPropertyClass::uacsPrefix($item?->property_class),
            'custodian_code' => $code !== '' ? $code : '00',
        ];
    }

    protected function resolveItem(Issuance $issuance): ?Item
    {
        if ($issuance->relationLoaded('item')) {
            return $issuance->item;
        }

        return Item::query()->with('category')->find($issuance->item_id);
    }

    protected function resolveUnitCost(Issuance $issuance, ?Item $item): ?float
    {
        if ($issuance->unit_cost !== null) {
            return (float) $issuance->unit_cost;
        }

        if ($item === null || $issuance->office_id === null) {
            return null;
        }

        return app(InventoryStockService::class)->resolveFifoUnitCost(
            (int) $item->id,
            (int) $issuance->office_id,
        );
    }

    protected function resolveAcquisitionYear(Issuance $issuance, ?Item $item): int
    {
        if ($item !== null && $issuance->unit_cost !== null) {
            $date = Acquisition::query()
                ->where('item_id', $item->id)
                ->when($issuance->office_id, fn ($q) => $q->where('office_id', $issuance->office_id))
                ->where('unit_cost', UnitCostKey::normalize((float) $issuance->unit_cost))
                ->orderByDesc('acquisition_date')
                ->value('acquisition_date');

            if ($date !== null) {
                return (int) date('Y', strtotime((string) $date));
            }
        }

        if ($item !== null) {
            $date = Acquisition::query()
                ->where('item_id', $item->id)
                ->when($issuance->office_id, fn ($q) => $q->where('office_id', $issuance->office_id))
                ->orderByDesc('acquisition_date')
                ->value('acquisition_date');

            if ($date !== null) {
                return (int) date('Y', strtotime((string) $date));
            }
        }

        return (int) ($issuance->issuance_date?->format('Y') ?? now()->format('Y'));
    }

    protected function resolveCustodianCode(Issuance $issuance): string
    {
        if (blank($issuance->department_id)) {
            return '00';
        }

        $department = $issuance->relationLoaded('department')
            ? $issuance->department
            : Department::query()->find($issuance->department_id);

        $code = trim((string) ($department?->code ?? ''));

        return $code !== '' ? $code : '00';
    }

    /**
     * @param  array<string, string>  $segments
     */
    protected function assignNumberFromSegments(array $segments): string
    {
        $bucketKey = $this->bucketKey($segments);

        return DB::transaction(function () use ($bucketKey, $segments): string {
            $bucket = PropertyNumberBucket::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    ['bucket_key' => $bucketKey],
                    ['next_sequence' => 1],
                );

            $sequence = (int) $bucket->next_sequence;
            $number = $this->formatNumber($segments, $sequence);

            $bucket->next_sequence = $sequence + 1;
            $bucket->save();

            return $number;
        });
    }

    /**
     * @param  array<string, string>  $segments
     */
    protected function bucketKey(array $segments): string
    {
        return implode('|', [
            $segments['value_category'],
            $segments['acq_year'],
            $segments['supply_type_code'],
            $segments['uacs_prefix'],
            $segments['custodian_code'],
        ]);
    }

    /**
     * @param  array<string, string>  $segments
     */
    protected function formatNumber(array $segments, int $sequence): string
    {
        $pattern = (string) config('inventory.semi_property_number.pattern');
        $replacements = [
            '{value_category}' => $segments['value_category'],
            '{acq_year}' => $segments['acq_year'],
            '{supply_type_code}' => $segments['supply_type_code'],
            '{uacs_prefix}' => $segments['uacs_prefix'],
            '{custodian_code}' => $segments['custodian_code'],
        ];

        $formatted = str_replace(array_keys($replacements), array_values($replacements), $pattern);

        if (preg_match('/\{seq:(\d+)\}/', $formatted, $matches)) {
            $pad = (int) $matches[1];
            $formatted = preg_replace(
                '/\{seq:\d+\}/',
                str_pad((string) $sequence, $pad, '0', STR_PAD_LEFT),
                $formatted,
                1,
            );
        }

        return $formatted;
    }

    /**
     * @throws ValidationException
     */
    public function assertDepartmentPresent(Issuance $issuance): void
    {
        if (blank($issuance->department_id)) {
            throw ValidationException::withMessages([
                'department_id' => 'Department is required for semi-expendable issuances (custodian/location segment in the property number).',
            ]);
        }

        $code = $this->resolveCustodianCode($issuance);
        if ($code === '00') {
            throw ValidationException::withMessages([
                'department_id' => 'The selected department must have a code configured for semi-expendable property numbers.',
            ]);
        }
    }
}
