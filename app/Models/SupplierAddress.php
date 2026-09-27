<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierAddress extends Model
{
    use LogsUserActivity;

    protected $fillable = [
        'supplier_id',
        'address',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    protected static function booted(): void
    {
        static::saved(function (SupplierAddress $address): void {
            if ($address->supplier_id === null) {
                return;
            }

            static::ensureSingleDefault(
                (int) $address->supplier_id,
                $address->is_default ? (int) $address->id : null,
            );
        });

        static::deleted(function (SupplierAddress $address): void {
            if ($address->supplier_id === null) {
                return;
            }

            static::ensureSingleDefault((int) $address->supplier_id);
        });
    }

    public static function ensureSingleDefault(int $supplierId, ?int $preferredId = null): void
    {
        $addresses = static::query()
            ->where('supplier_id', $supplierId)
            ->orderBy('id')
            ->get(['id', 'is_default']);

        if ($addresses->isEmpty()) {
            return;
        }

        if ($addresses->count() === 1) {
            $only = $addresses->first();
            if ($only !== null && ! $only->is_default) {
                static::query()->whereKey($only->id)->update(['is_default' => true]);
            }

            return;
        }

        $defaultIds = $addresses
            ->filter(fn (SupplierAddress $address): bool => $address->is_default)
            ->pluck('id');

        $preferredIsDefault = $preferredId !== null && $defaultIds->contains($preferredId);

        if ($preferredIsDefault) {
            $keepId = $preferredId;
        } elseif ($defaultIds->count() === 1) {
            return;
        } elseif ($defaultIds->isEmpty()) {
            $keepId = $addresses->first()?->id;
        } else {
            $keepId = $defaultIds->last();
        }

        if ($keepId === null) {
            return;
        }

        static::query()
            ->where('supplier_id', $supplierId)
            ->where('id', '!=', $keepId)
            ->update(['is_default' => false]);

        static::query()->whereKey($keepId)->update(['is_default' => true]);
    }

    public static function replaceFromForm(Supplier $supplier, string $address, ?string $secondaryAddress): void
    {
        $primary = trim($address);
        $secondary = trim((string) $secondaryAddress);

        if ($secondary === $primary) {
            $secondary = '';
        }

        static::query()->where('supplier_id', $supplier->id)->delete();

        static::query()->create([
            'supplier_id' => $supplier->id,
            'address' => $primary,
            'is_default' => true,
        ]);

        if ($secondary !== '') {
            static::query()->create([
                'supplier_id' => $supplier->id,
                'address' => $secondary,
                'is_default' => false,
            ]);
        }
    }

    public static function remember(Supplier $supplier, string $address): self
    {
        $normalized = trim($address);

        /** @var self $record */
        $record = static::query()->firstOrCreate(
            [
                'supplier_id' => $supplier->id,
                'address' => $normalized,
            ],
            [
                'is_default' => ! $supplier->addresses()->exists(),
            ],
        );

        return $record;
    }

    /**
     * @return list<string>
     */
    public static function suggestionsForSupplier(?int $supplierId): array
    {
        if ($supplierId === null) {
            return [];
        }

        return static::query()
            ->where('supplier_id', $supplierId)
            ->orderByDesc('is_default')
            ->orderBy('address')
            ->pluck('address')
            ->unique()
            ->values()
            ->all();
    }
}
