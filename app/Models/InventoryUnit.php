<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryUnit extends Model
{
    use HasFactory;

    public const STATUS_IN_STOCK = 'in_stock';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_TRANSFERRED = 'transferred';

    public const STATUS_DISPOSED = 'disposed';

    /** Returned for repair: held at the supply office, not available stock, not issuable. */
    public const STATUS_UNDER_REPAIR = 'under_repair';

    protected $fillable = [
        'property_number',
        'acquisition_id',
        'stock_opening_balance_id',
        'item_id',
        'office_id',
        'unit_cost',
        'status',
        'issuance_id',
        'repair_source_issuance_id',
        'article',
        'description',
        'stock_number',
        'unit_of_measure',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
        ];
    }

    public function acquisition(): BelongsTo
    {
        return $this->belongsTo(Acquisition::class);
    }

    public function stockOpeningBalance(): BelongsTo
    {
        return $this->belongsTo(StockOpeningBalance::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class);
    }

    /**
     * Issuance the unit was returned from for repair (used to re-issue to the same employee).
     */
    public function repairSourceIssuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class, 'repair_source_issuance_id');
    }

    public function isInStock(): bool
    {
        return $this->status === self::STATUS_IN_STOCK;
    }

    /**
     * Property tags accountable to an office for regional physical count (warehouse + issued in use).
     *
     * @return array<int, string>
     */
    public static function accountableStatuses(): array
    {
        return [
            self::STATUS_IN_STOCK,
            self::STATUS_ISSUED,
            self::STATUS_UNDER_REPAIR,
        ];
    }
}
