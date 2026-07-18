<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'name',
        'tin',
    ];

    public function addresses(): HasMany
    {
        return $this->hasMany(SupplierAddress::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public static function remember(string $name, ?string $tin = null, ?string $address = null): self
    {
        $normalizedName = trim($name);
        $normalizedTin = self::normalizeTin($tin);

        /** @var self $supplier */
        $supplier = static::query()->firstOrCreate(
            ['name' => $normalizedName],
            ['tin' => $normalizedTin],
        );

        if ($normalizedTin !== null && blank($supplier->tin)) {
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
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }
}
