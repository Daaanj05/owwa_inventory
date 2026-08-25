<?php

namespace App\Models;

use App\Support\UnitCostKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOpeningBalance extends Model
{
    protected $fillable = [
        'item_id',
        'office_id',
        'unit_cost',
        'quantity',
        'recorded_by',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'quantity' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public static function findForPosition(int $itemId, int $officeId, ?float $unitCost): ?self
    {
        return self::query()
            ->where('item_id', $itemId)
            ->where('office_id', $officeId)
            ->where('unit_cost', UnitCostKey::normalize($unitCost))
            ->first();
    }

    public static function quantityForPosition(int $itemId, int $officeId, ?float $unitCost): int
    {
        return (int) (self::findForPosition($itemId, $officeId, $unitCost)?->quantity ?? 0);
    }
}
