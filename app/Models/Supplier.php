<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use LogsUserActivity;

    protected $fillable = [
        'name',
        'tin',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(SupplierAddress::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archive(): void
    {
        if ($this->isArchived()) {
            return;
        }

        $this->update(['archived_at' => now()]);
    }

    public function restoreFromArchive(): void
    {
        if (! $this->isArchived()) {
            return;
        }

        $this->update(['archived_at' => null]);
    }

    public static function remember(string $name, ?string $tin = null, ?string $address = null): self
    {
        $normalizedName = trim($name);
        $normalizedTin = self::normalizeTin($tin);

        /** @var self $supplier */
        $supplier = static::query()->firstOrNew(
            ['name' => $normalizedName],
        );

        if ($supplier->exists && $supplier->isArchived()) {
            $supplier->restoreFromArchive();
        }

        if (! $supplier->exists) {
            $supplier->tin = $normalizedTin;
            $supplier->save();
        } elseif ($normalizedTin !== null && blank($supplier->tin)) {
            $supplier->update(['tin' => $normalizedTin]);
        } elseif ($normalizedTin !== null && $supplier->tin !== $normalizedTin) {
            $supplier->update(['tin' => $normalizedTin]);
        }

        if (filled($address)) {
            SupplierAddress::remember($supplier, (string) $address);
        }

        return $supplier->fresh(['addresses']) ?? $supplier;
    }

    public static function normalizeTin(?string $tin): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $tin);

        return filled($digits) ? $digits : null;
    }

    /**
     * @return list<string>
     */
    public static function nameSuggestions(): array
    {
        return static::query()
            ->active()
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }
}
