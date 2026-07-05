<?php

namespace App\Models;

use App\Support\UnitCostKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockPositionRestockFlag extends Model
{
    protected $fillable = [
        'item_id',
        'office_id',
        'unit_cost',
        'is_inactive_for_restock',
        'inactive_at',
        'inactive_by',
        'inactive_note',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'is_inactive_for_restock' => 'boolean',
            'inactive_at' => 'datetime',
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

    public function inactiveBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inactive_by');
    }

    public static function findForPosition(int $itemId, int $officeId, ?float $unitCost): ?self
    {
        $normalized = UnitCostKey::normalize($unitCost);

        return self::query()
            ->where('item_id', $itemId)
            ->where('office_id', $officeId)
            ->where('unit_cost', UnitCostKey::normalize($unitCost))
            ->first();
    }

    public static function isInactiveForRestock(int $itemId, int $officeId, ?float $unitCost): bool
    {
        return (bool) self::findForPosition($itemId, $officeId, $unitCost)?->is_inactive_for_restock;
    }

    public static function markInactive(
        int $itemId,
        int $officeId,
        ?float $unitCost,
        int $userId,
        ?string $note = null,
    ): self {
        $flag = self::query()->updateOrCreate(
            [
                'item_id' => $itemId,
                'office_id' => $officeId,
                'unit_cost' => UnitCostKey::normalize($unitCost),
            ],
            [
                'is_inactive_for_restock' => true,
                'inactive_at' => now(),
                'inactive_by' => $userId,
                'inactive_note' => $note,
            ],
        );

        return $flag;
    }

    public static function markActive(int $itemId, int $officeId, ?float $unitCost): self
    {
        $flag = self::query()->updateOrCreate(
            [
                'item_id' => $itemId,
                'office_id' => $officeId,
                'unit_cost' => UnitCostKey::normalize($unitCost),
            ],
            [
                'is_inactive_for_restock' => false,
                'inactive_at' => null,
                'inactive_by' => null,
                'inactive_note' => null,
            ],
        );

        return $flag;
    }

    public static function reactivateOnAcquisition(int $itemId, int $officeId, ?float $unitCost): void
    {
        $flag = self::findForPosition($itemId, $officeId, $unitCost);
        if ($flag !== null && $flag->is_inactive_for_restock) {
            self::markActive($itemId, $officeId, $unitCost);
        }
    }
}
