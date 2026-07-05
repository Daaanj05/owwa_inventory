<?php

namespace App\Models;

use App\Support\UnitCostKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemStockBucket extends Model
{
    protected $fillable = [
        'item_id',
        'unit_cost',
        'property_number',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public static function normalizedCost(?float $unitCost): string
    {
        return UnitCostKey::normalize($unitCost);
    }

    public static function findForItemCost(int $itemId, ?float $unitCost): ?self
    {
        return self::query()
            ->where('item_id', $itemId)
            ->where('unit_cost', UnitCostKey::normalize($unitCost))
            ->first();
    }

    public static function firstOrCreateForItemCost(int $itemId, ?float $unitCost): self
    {
        $existing = self::findForItemCost($itemId, $unitCost);
        if ($existing !== null) {
            return $existing;
        }

        return self::query()->create([
            'item_id' => $itemId,
            'unit_cost' => UnitCostKey::normalize($unitCost),
        ]);
    }
}
