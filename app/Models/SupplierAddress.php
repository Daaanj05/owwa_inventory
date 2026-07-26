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
