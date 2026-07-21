<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Office;
use App\Models\PropertyNumberBucket;
use App\Models\UacsObjectCode;
use App\Support\ItemPropertyClass;
use App\Support\PpePropertyType;
use App\Support\SemiExpendableValueCategory;
use App\Support\SupplyOfficeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogAssetNumberService
{
    public const SERIES_SEMI = 'catalog_inventory_item';

    public const SERIES_PPE = 'catalog_property_number';

    public function assignForItem(Item $item): void
    {
        if (! config('inventory.auto_generate_property_numbers', true)) {
            return;
        }

        $item->loadMissing(['category', 'uacsObjectCode']);
        $slug = $item->category?->getTemplateSlug();

        if (! in_array($slug, ['semi_expendable', 'ppe'], true)) {
            return;
        }

        if (blank($item->uacs_object_code_id)) {
            return;
        }

        if ($slug === 'semi_expendable') {
            if (blank($item->property_class)) {
                $item->loadMissing('uacsObjectCode');
                $fromUacs = $item->uacsObjectCode?->property_class
                    ?? UacsObjectCode::query()->whereKey($item->uacs_object_code_id)->value('property_class');

                if (filled($fromUacs)) {
                    $item->property_class = (string) $fromUacs;
                }
            }

            if (blank($item->property_class)) {
                return;
            }
        }

        if ($slug === 'ppe') {
            if (blank($item->ppe_type)) {
                $item->loadMissing('uacsObjectCode');
                $fromUacs = $item->uacsObjectCode?->property_class
                    ?? UacsObjectCode::query()->whereKey($item->uacs_object_code_id)->value('property_class');

                if (filled($fromUacs)) {
                    $item->ppe_type = (string) $fromUacs;
                }
            }

            if (blank($item->ppe_type)) {
                return;
            }
        }

        try {
            if ($slug === 'semi_expendable') {
                if (filled($item->semi_expendable_property_number)) {
                    return;
                }

                $item->semi_expendable_property_number = $this->mintSemiProvisional($item);

                return;
            }

            if (filled($item->ppe_property_number)) {
                return;
            }

            $item->ppe_property_number = $this->mintPpe($item);
        } catch (ValidationException) {
            // Location/UACS may be incomplete during tests or partial creates — leave blank.
        }
    }

    public function mintSemiProvisional(Item $item): string
    {
        $segments = $this->semiSegments($item, (string) config('inventory.semi_property_number.provisional_prefix', 'TEMP'));

        return $this->mintFromSegments(self::SERIES_SEMI, $segments);
    }

    public function mintPpe(Item $item): string
    {
        $segments = $this->ppeSegments($item);

        return $this->mintFromSegments(self::SERIES_PPE, $segments);
    }

    /**
     * Finalize TEMP-* inventory item number using first acquisition unit cost.
     */
    public function finalizeSemiWithUnitCost(Item $item, ?float $unitCost): string
    {
        $item->loadMissing(['category', 'uacsObjectCode']);
        $current = $item->semi_expendable_property_number;

        if (blank($current)) {
            $prefix = SemiExpendableValueCategory::prefixForUnitCost($unitCost);
            $number = $this->mintFromSegments(self::SERIES_SEMI, $this->semiSegments($item, $prefix));
            $item->forceFill(['semi_expendable_property_number' => $number])->saveQuietly();

            return $number;
        }

        if (! str_starts_with((string) $current, 'TEMP-')) {
            return (string) $current;
        }

        SemiExpendableValueCategory::assertWithinSemiCap($unitCost);
        $prefix = SemiExpendableValueCategory::prefixForUnitCost($unitCost);
        $finalized = preg_replace('/^TEMP-/', $prefix.'-', (string) $current, 1) ?? (string) $current;

        $item->forceFill(['semi_expendable_property_number' => $finalized])->saveQuietly();

        return $finalized;
    }

    public function catalogIdentifierForItem(Item $item): ?string
    {
        $item->loadMissing('category');
        $slug = $item->category?->getTemplateSlug();

        return match ($slug) {
            'consumables' => $item->item_code,
            'semi_expendable' => $item->semi_expendable_property_number ?: null,
            'ppe' => $item->ppe_property_number ?: null,
            default => $item->item_code,
        };
    }

    public function catalogIdentifierLabel(?string $categorySlug): string
    {
        return match ($categorySlug) {
            'ppe' => 'Property No.',
            'semi_expendable' => 'Inventory item no.',
            default => 'Stock No.',
        };
    }

    /**
     * @return array{value_category: string, acq_year: string, class_code: string, uacs_code: string, location: string}
     */
    public function semiSegments(Item $item, string $valueCategory): array
    {
        return [
            'value_category' => $valueCategory,
            'acq_year' => now()->format('Y'),
            'class_code' => ItemPropertyClass::supplyTypeCode($item->property_class),
            'uacs_code' => $this->resolveUacsCode($item),
            'location' => $this->resolveLocationCode(),
        ];
    }

    /**
     * @return array{acq_year: string, class_code: string, uacs_code: string, location: string}
     */
    public function ppeSegments(Item $item): array
    {
        return [
            'acq_year' => now()->format('Y'),
            'class_code' => PpePropertyType::supplyTypeCode($item->ppe_type),
            'uacs_code' => $this->resolveUacsCode($item),
            'location' => $this->resolveLocationCode(),
        ];
    }

    /**
     * @param  array<string, string>  $segments
     */
    protected function mintFromSegments(string $series, array $segments): string
    {
        $year = (int) ($segments['acq_year'] ?? now()->format('Y'));

        return DB::transaction(function () use ($series, $segments, $year): string {
            $bucketKey = $series.'|'.$year;
            $bucket = PropertyNumberBucket::query()
                ->where('bucket_key', $bucketKey)
                ->lockForUpdate()
                ->first();

            if ($bucket === null) {
                $bucket = PropertyNumberBucket::query()->create([
                    'bucket_key' => $bucketKey,
                    'next_sequence' => 1,
                ]);
                $bucket = PropertyNumberBucket::query()
                    ->whereKey($bucket->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $seq = (int) $bucket->next_sequence;
            $bucket->update(['next_sequence' => $seq + 1]);

            return $this->format($series, $segments, $seq);
        });
    }

    /**
     * @param  array<string, string>  $segments
     */
    protected function format(string $series, array $segments, int $seq): string
    {
        $padded = str_pad((string) $seq, 3, '0', STR_PAD_LEFT);

        if ($series === self::SERIES_SEMI) {
            return implode('-', [
                $segments['value_category'],
                $segments['acq_year'],
                $segments['class_code'],
                $segments['uacs_code'],
                $padded,
                $segments['location'],
            ]);
        }

        return implode('-', [
            $segments['acq_year'],
            $segments['class_code'],
            $segments['uacs_code'],
            $padded,
            $segments['location'],
        ]);
    }

    protected function resolveUacsCode(Item $item): string
    {
        $item->loadMissing('uacsObjectCode');
        $code = $item->uacsObjectCode?->code;

        if (filled($code)) {
            return (string) $code;
        }

        if (filled($item->uacs_object_code_id)) {
            $code = UacsObjectCode::query()->whereKey($item->uacs_object_code_id)->value('code');
            if (filled($code)) {
                return (string) $code;
            }
        }

        $fallback = UacsObjectCode::query()->active()->where('code', '106')->value('code')
            ?? UacsObjectCode::query()->active()->orderBy('code')->value('code');

        if (filled($fallback)) {
            return (string) $fallback;
        }

        return '106';
    }

    protected function resolveLocationCode(): string
    {
        $office = app(SupplyOfficeResolver::class)->resolveOffice();
        $code = $office?->code;

        if (blank($code)) {
            $code = Office::query()
                ->active()
                ->whereNotNull('code')
                ->where('code', '!=', '')
                ->orderByDesc('is_regional_supply')
                ->orderBy('name')
                ->value('code');
        }

        if (blank($code)) {
            return '00';
        }

        return strtoupper(preg_replace('/\s+/', '', (string) $code) ?? (string) $code);
    }
}
