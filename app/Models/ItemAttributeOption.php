<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
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
            $options[(string) $current] = (string) $current;
        }

        return $options;
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
