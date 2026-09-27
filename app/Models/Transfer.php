<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transfer extends Model
{
    use HasFactory, LogsUserActivity, SoftDeletes;

    public const TYPE_DONATION = 'donation';

    public const TYPE_RELOCATE = 'relocate';

    public const TYPE_REASSIGNMENT = 'reassignment';

    public const TYPE_RETURN = 'return';

    public const TYPE_OTHERS = 'others';

    protected $fillable = [
        'reference_code', 'item_id', 'inventory_unit_id', 'from_office_id', 'to_office_id',
        'quantity', 'unit_cost', 'transfer_date', 'transfer_type', 'transfer_type_other',
        'reason_for_transfer', 'from_accountable_officer', 'to_accountable_officer',
        'remarks', 'property_number', 'condition',
        'approved_by_printed_name', 'released_by_printed_name', 'received_by_printed_name',
        'approved_by_designation', 'released_by_designation', 'received_by_designation',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function inventoryUnit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class);
    }

    public function fromOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'from_office_id');
    }

    public function toOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'to_office_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_DONATION => 'Donation',
            self::TYPE_RELOCATE => 'Relocate',
            self::TYPE_REASSIGNMENT => 'Reassignment',
            self::TYPE_RETURN => 'Return to stock',
            self::TYPE_OTHERS => 'Others',
        ];
    }

    public static function typeLabel(?string $type, ?string $other = null): string
    {
        if ($type === self::TYPE_OTHERS && filled($other)) {
            return 'Others: '.$other;
        }

        return self::typeOptions()[$type] ?? (filled($type) ? ucfirst(str_replace('_', ' ', $type)) : '—');
    }

    public function isReturnToStock(): bool
    {
        return $this->transfer_type === self::TYPE_RETURN;
    }

    protected static function booted(): void
    {
        static::saved(function (Transfer $transfer): void {
            $transfer->rememberSignatoryNames();
        });
    }

    public function rememberSignatoryNames(): void
    {
        ProcurementSignatoryName::remember(
            ProcurementSignatoryName::ROLE_TRANSFER_APPROVED,
            $this->approved_by_printed_name,
            $this->approved_by_designation,
        );
        ProcurementSignatoryName::remember(
            ProcurementSignatoryName::ROLE_TRANSFER_RELEASED,
            $this->released_by_printed_name,
            $this->released_by_designation,
        );
        ProcurementSignatoryName::remember(
            ProcurementSignatoryName::ROLE_TRANSFER_RECEIVED,
            $this->received_by_printed_name,
            $this->received_by_designation,
        );
    }
}
