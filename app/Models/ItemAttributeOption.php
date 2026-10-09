<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use App\Support\ConsumableInventoryType;
use App\Support\ItemPropertyClass;
use App\Support\PpePropertyType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ItemAttributeOption extends Model
{
    use LogsUserActivity;

    public const KIND_UNIT = 'unit';

    public const KIND_INVENTORY_TYPE = 'inventory_type';

    public const KIND_PROPERTY_CLASS = 'property_class';

    public const KIND_PPE_TYPE = 'ppe_type';

    protected $fillable = [
        'kind',
        'value',
        'label',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /**
     * @return array<string, string> value => label
     */
    public static function optionsForKind(string $kind): array
    {
        return static::query()
            ->active()
            ->ofKind($kind)
            ->orderBy('label')
            ->pluck('label', 'value')
            ->all();
    }

    /**
     * @return array<string, string> value => label
     */
    public static function optionsForKindIncluding(string $kind, mixed $current): array
    {
        $options = static::optionsForKind($kind);
        if (filled($current) && ! array_key_exists((string) $current, $options)) {
            $options[(string) $current] = static::labelFor($kind, (string) $current);
        }

        return $options;
    }

    /**
     * Official keys stay valid. Any other value must be an active attribute-list row.
     */
    public static function resolveStoredValue(string $kind, mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $official = match ($kind) {
            self::KIND_PROPERTY_CLASS => self::officialKey(ItemPropertyClass::resolve($raw), ItemPropertyClass::options()),
            self::KIND_PPE_TYPE => self::officialKey(PpePropertyType::resolve($raw), PpePropertyType::options()),
            self::KIND_INVENTORY_TYPE => self::officialKey(ConsumableInventoryType::resolve($raw), ConsumableInventoryType::options()),
            default => null,
        };

        if ($official !== null) {
            return $official;
        }

        $listed = static::optionsForKind($kind);
        if (array_key_exists($raw, $listed)) {
            return $raw;
        }

        foreach ($listed as $stored => $label) {
            if (strcasecmp(trim($label), $raw) === 0) {
                return (string) $stored;
            }
        }

        return null;
    }

    public static function labelFor(string $kind, ?string $value): string
    {
        if (blank($value)) {
            return '';
        }

        $label = static::query()
            ->ofKind($kind)
            ->where('value', $value)
            ->value('label');

        if (is_string($label) && $label !== '') {
            return $label;
        }

        return match ($kind) {
            self::KIND_PROPERTY_CLASS => ItemPropertyClass::label($value) ?? $value,
            self::KIND_PPE_TYPE => PpePropertyType::label($value) ?? $value,
            self::KIND_INVENTORY_TYPE => ($inventoryLabel = ConsumableInventoryType::label($value)) !== ''
                ? $inventoryLabel
                : $value,
            default => $value,
        };
    }

    /**
     * @param  array<string, string>  $officialOptions
     */
    protected static function officialKey(?string $resolved, array $officialOptions): ?string
    {
        if ($resolved === null || ! array_key_exists($resolved, $officialOptions)) {
            return null;
        }

        return $resolved;
    }

    /**
     * @return array<string, string>
     */
    public static function kindOptions(): array
    {
        return [
            self::KIND_UNIT => 'Measurement unit',
            self::KIND_INVENTORY_TYPE => 'Inventory type',
            self::KIND_PROPERTY_CLASS => 'Property class',
            self::KIND_PPE_TYPE => 'Type of PPE',
        ];
    }
}
