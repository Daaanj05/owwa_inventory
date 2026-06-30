<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\PropertyNumberBucket;
use App\Support\ItemPropertyClass;
use App\Support\SemiExpendableValueCategory;
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
        $segments = $this->previewSegments($issuance, $item);
        $bucketKey = $this->bucketKey($segments);
        $sequence = PropertyNumberBucket::query()
            ->where('bucket_key', $bucketKey)
            ->value('next_sequence') ?? 1;

        return $this->formatNumber($segments, (int) $sequence);
    }

    public function assignForIssuance(Issuance $issuance): string
    {
        $item = $this->resolveItem($issuance);
        $unitCost = $this->resolveUnitCost($issuance, $item);

        SemiExpendableValueCategory::assertWithinSemiCap($unitCost);

        $segments = $this->previewSegments($issuance, $item);
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

    public function assignForAcquisition(Acquisition $acquisition): string
    {
        $item = $this->resolveAcquisitionItem($acquisition);
        $unitCost = $acquisition->unit_cost !== null ? (float) $acquisition->unit_cost : null;

        SemiExpendableValueCategory::assertWithinSemiCap($unitCost);

        $segments = $this->previewSegmentsForAcquisition($acquisition, $item);
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
     * @return array<string, string>
     */
    public function previewSegmentsForAcquisition(Acquisition $acquisition, ?Item $item = null): array
    {
        $item ??= $this->resolveAcquisitionItem($acquisition);
        $unitCost = $acquisition->unit_cost !== null ? (float) $acquisition->unit_cost : null;
        $acqYear = (int) ($acquisition->acquisition_date?->format('Y') ?? now()->format('Y'));

        return [
            'value_category' => SemiExpendableValueCategory::prefixForUnitCost($unitCost),
            'acq_year' => (string) $acqYear,
            'supply_type_code' => ItemPropertyClass::supplyTypeCode($item?->property_class),
            'uacs_prefix' => ItemPropertyClass::uacsPrefix($item?->property_class),
            'custodian_code' => $this->resolveOfficeCustodianCode($acquisition),
        ];
    }

    protected function resolveAcquisitionItem(Acquisition $acquisition): ?Item
    {
        if ($acquisition->relationLoaded('item')) {
            return $acquisition->item;
        }

        return Item::query()->with('category')->find($acquisition->item_id);
    }

    protected function resolveOfficeCustodianCode(Acquisition $acquisition): string
    {
        $office = $acquisition->relationLoaded('office')
            ? $acquisition->office
            : ($acquisition->office_id ? \App\Models\Office::query()->find($acquisition->office_id) : null);

        $code = trim((string) ($office?->code ?? ''));

        return $code !== '' ? $code : '00';
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

        if ($item === null) {
            return null;
        }

        $unitCost = Acquisition::query()
            ->where('item_id', $item->id)
            ->when($issuance->office_id, fn ($q) => $q->where('office_id', $issuance->office_id))
            ->orderByDesc('acquisition_date')
            ->value('unit_cost');

        return $unitCost !== null ? (float) $unitCost : null;
    }

    protected function resolveAcquisitionYear(Issuance $issuance, ?Item $item): int
    {
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
