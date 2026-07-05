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
        'item_category_id', 'name', 'unit', 'item_code', 'semi_expendable_property_number',
        'value_type', 'property_class', 'reorder_level', 'description', 'days_to_consume',
        'estimated_useful_life', 'serial_number',
    ];

    public function resolvedSemiExpendablePropertyNumber(?float $unitCost = null): ?string
    {
        if ($unitCost !== null) {
            $bucket = ItemStockBucket::findForItemCost((int) $this->id, $unitCost);

            return filled($bucket?->property_number) ? (string) $bucket->property_number : null;
        }

        $buckets = ItemStockBucket::query()
            ->where('item_id', $this->id)
            ->whereNotNull('property_number')
            ->get();

        if ($buckets->count() === 1) {
            return (string) $buckets->first()->property_number;
        }

        if ($buckets->count() > 1) {
            return null;
        }

        if (filled($this->semi_expendable_property_number)) {
            return (string) $this->semi_expendable_property_number;
        }

        $fromIssuance = $this->issuances()
            ->whereNotNull('property_number')
            ->orderByDesc('issuance_date')
            ->value('property_number');

        return filled($fromIssuance) ? (string) $fromIssuance : null;
    }

    public function stockBuckets(): HasMany
    {
        return $this->hasMany(ItemStockBucket::class);
    }

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
