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
        'item_category_id', 'name', 'base_name', 'sub_item', 'unit', 'item_code',
        'semi_expendable_property_number', 'ppe_property_number', 'value_type', 'property_class',
        'uacs_object_code_id', 'reorder_level', 'description', 'days_to_consume',
        'estimated_useful_life', 'serial_number', 'archived_at',
    ];

    public static function mergeDisplayName(?string $baseName, ?string $subItem): string
    {
        $base = trim((string) $baseName);
        $sub = trim((string) $subItem);

        if ($base === '') {
            return $sub;
        }

        if ($sub === '') {
            return $base;
        }

        return $base.' '.$sub;
    }

    public function syncMergedName(): void
    {
        $base = filled($this->base_name) ? $this->base_name : $this->name;
        $this->name = self::mergeDisplayName($base, $this->sub_item);
        $this->base_name = filled($this->base_name) ? trim((string) $this->base_name) : $this->name;
    }

    public function resolvedSemiExpendablePropertyNumber(?float $unitCost = null): ?string
    {
        if (filled($this->semi_expendable_property_number)) {
            return (string) $this->semi_expendable_property_number;
        }

        if ($unitCost !== null) {
            $bucket = ItemStockBucket::findForItemCost((int) $this->id, $unitCost);

            return filled($bucket?->property_number) ? (string) $bucket->property_number : null;
        }

        $fromBucket = ItemStockBucket::query()
            ->where('item_id', $this->id)
            ->whereNotNull('property_number')
            ->orderBy('id')
            ->value('property_number');

        if (filled($fromBucket)) {
            return (string) $fromBucket;
        }

        $fromIssuance = $this->issuances()
            ->whereNotNull('property_number')
            ->orderBy('issuance_date')
            ->orderBy('id')
            ->value('property_number');

        return filled($fromIssuance) ? (string) $fromIssuance : null;
    }

    public function resolvedPpePropertyNumber(): ?string
    {
        return filled($this->ppe_property_number) ? (string) $this->ppe_property_number : null;
    }

    public function catalogAssetIdentifier(): ?string
    {
        return app(\App\Services\CatalogAssetNumberService::class)->catalogIdentifierForItem($this);
    }

    public function stockBuckets(): HasMany
    {
        return $this->hasMany(ItemStockBucket::class);
    }

    public function uacsObjectCode(): BelongsTo
    {
        return $this->belongsTo(UacsObjectCode::class);
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
