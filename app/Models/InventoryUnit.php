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

    protected $fillable = [
        'property_number',
        'acquisition_id',
        'item_id',
        'office_id',
        'status',
        'issuance_id',
        'article',
        'description',
        'stock_number',
        'unit_of_measure',
    ];

    public function acquisition(): BelongsTo
    {
        return $this->belongsTo(Acquisition::class);
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

    public function isInStock(): bool
    {
        return $this->status === self::STATUS_IN_STOCK;
    }
}
