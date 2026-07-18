<?php

namespace App\Models;

use App\Support\UnitCostKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class StockPositionRestockFlag extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AUTOMATIC = 'automatic';

    protected $fillable = [
        'item_id',
        'office_id',
        'unit_cost',
        'is_inactive_for_restock',
        'inactive_at',
        'inactive_by',
        'inactive_note',
        'zero_stock_since',
        'inactive_source',
        'auto_inactive_snoozed_until',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'is_inactive_for_restock' => 'boolean',
            'inactive_at' => 'datetime',
            'zero_stock_since' => 'date',
            'auto_inactive_snoozed_until' => 'date',
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

    public function statusLabel(): string
    {
        if (! $this->is_inactive_for_restock) {
            return 'Active';
        }

        if ($this->inactive_source === self::SOURCE_AUTOMATIC) {
            return 'Inactive — no stock for 1 year';
        }

        return 'Inactive';
    }

    public static function markInactive(
        int $itemId,
        int $officeId,
        ?float $unitCost,
        int $userId,
        ?string $note = null,
    ): self {
        return self::query()->updateOrCreate(
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
                'inactive_source' => self::SOURCE_MANUAL,
                'auto_inactive_snoozed_until' => null,
            ],
        );
    }

    public static function markAutomaticallyInactive(
        int $itemId,
        int $officeId,
        ?float $unitCost,
        Carbon|string $zeroStockSince,
    ): self {
        $existing = self::findForPosition($itemId, $officeId, $unitCost);

        if ($existing !== null
            && $existing->is_inactive_for_restock
            && $existing->inactive_source === self::SOURCE_MANUAL) {
            $existing->forceFill([
                'zero_stock_since' => $zeroStockSince,
            ])->save();

            return $existing;
        }

        if ($existing?->auto_inactive_snoozed_until !== null
            && $existing->auto_inactive_snoozed_until->gte(now()->startOfDay())) {
            $existing->forceFill([
                'zero_stock_since' => $zeroStockSince,
            ])->save();

            return $existing;
        }

        return self::query()->updateOrCreate(
            [
                'item_id' => $itemId,
                'office_id' => $officeId,
                'unit_cost' => UnitCostKey::normalize($unitCost),
            ],
            [
                'is_inactive_for_restock' => true,
                'inactive_at' => now(),
                'inactive_by' => null,
                'inactive_note' => 'No stock for 1 year',
                'inactive_source' => self::SOURCE_AUTOMATIC,
                'zero_stock_since' => $zeroStockSince,
                'auto_inactive_snoozed_until' => null,
            ],
        );
    }

    public static function markActive(
        int $itemId,
        int $officeId,
        ?float $unitCost,
        bool $snoozeAutomaticIfStillZero = false,
    ): self {
        $payload = [
            'is_inactive_for_restock' => false,
            'inactive_at' => null,
            'inactive_by' => null,
            'inactive_note' => null,
            'inactive_source' => null,
            'auto_inactive_snoozed_until' => $snoozeAutomaticIfStillZero
                ? now()->addDays(30)->toDateString()
                : null,
        ];

        if (! $snoozeAutomaticIfStillZero) {
            $payload['zero_stock_since'] = null;
        }

        return self::query()->updateOrCreate(
            [
                'item_id' => $itemId,
                'office_id' => $officeId,
                'unit_cost' => UnitCostKey::normalize($unitCost),
            ],
            $payload,
        );
    }

    public static function reactivateOnAcquisition(int $itemId, int $officeId, ?float $unitCost): void
    {
        $flag = self::findForPosition($itemId, $officeId, $unitCost);
        if ($flag === null) {
            return;
        }

        self::markActive($itemId, $officeId, $unitCost, snoozeAutomaticIfStillZero: false);
    }

    public static function rememberZeroStockSince(
        int $itemId,
        int $officeId,
        ?float $unitCost,
        Carbon|string|null $zeroStockSince,
    ): self {
        return self::query()->updateOrCreate(
            [
                'item_id' => $itemId,
                'office_id' => $officeId,
                'unit_cost' => UnitCostKey::normalize($unitCost),
            ],
            [
                'zero_stock_since' => $zeroStockSince,
            ],
        );
    }
}
