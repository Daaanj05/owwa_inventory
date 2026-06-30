<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Issuance extends Model
{
    use HasFactory, LogsUserActivity, SoftDeletes;

    protected $fillable = [
        'reference_code', 'item_id', 'office_id', 'department_id', 'requisition_id',
        'quantity', 'unit_cost', 'amount', 'issuance_date', 'remarks',
        'property_number', 'estimated_useful_life', 'eul_expires_at', 'received_from_name',
        'custodian_printed_name', 'accounting_staff_printed_name',
        'custodian_designation', 'issued_to_designation',
        'issued_by', 'issued_to',
    ];

    protected function casts(): array
    {
        return [
            'issuance_date' => 'date',
            'eul_expires_at' => 'date',
            'unit_cost' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Issuance $issuance): void {
            static::applyPricingFromAcquisitions($issuance);
        });
    }

    protected static function applyPricingFromAcquisitions(Issuance $issuance): void
    {
        if ($issuance->item_id && ($issuance->isDirty('item_id') || $issuance->unit_cost === null)) {
            $unitCost = Acquisition::query()
                ->where('item_id', $issuance->item_id)
                ->orderByDesc('acquisition_date')
                ->value('unit_cost');

            if ($unitCost !== null) {
                $issuance->unit_cost = $unitCost;
            }
        }

        if ($issuance->quantity !== null && $issuance->unit_cost !== null) {
            $quantity = (float) $issuance->quantity;
            $unitCost = (float) $issuance->unit_cost;

            $issuance->amount = $quantity * $unitCost;
        }
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function issuedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_to');
    }

    public function inventoryUnit(): HasOne
    {
        return $this->hasOne(InventoryUnit::class);
    }

    /**
     * @return HasMany<UsefulLifeExtension, $this>
     */
    public function usefulLifeExtensions(): HasMany
    {
        return $this->hasMany(UsefulLifeExtension::class);
    }
}
