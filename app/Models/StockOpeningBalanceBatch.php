<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockOpeningBalanceBatch extends Model
{
    protected $fillable = [
        'office_id',
        'item_category_id',
        'reference_code',
        'reference',
        'recorded_on',
        'recorded_by',
        'recorded_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_on' => 'date',
            'recorded_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->confirmed_at === null;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function itemCategory(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockOpeningBalance::class, 'batch_id');
    }
}
