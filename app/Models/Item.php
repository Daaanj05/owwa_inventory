<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use HasFactory, LogsUserActivity;

    protected $fillable = [
        'item_category_id', 'name', 'unit', 'item_code', 'value_type', 'property_class',
        'reorder_level', 'description', 'days_to_consume', 'estimated_useful_life', 'serial_number',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function acquisitions(): HasMany
    {
        return $this->hasMany(Acquisition::class);
    }

    public function issuances(): HasMany
    {
        return $this->hasMany(Issuance::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }

    public function disposals(): HasMany
    {
        return $this->hasMany(Disposal::class);
    }

    public function requisitionItems(): HasMany
    {
        return $this->hasMany(RequisitionItem::class);
    }
}
