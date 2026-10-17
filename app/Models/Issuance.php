<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Issuance extends Model
{
    use HasFactory, LogsUserActivity, SoftDeletes;

    public const USEFUL_LIFE_STILL_USABLE = 'still_usable';

    public const USEFUL_LIFE_NEEDS_REPLACEMENT = 'needs_replacement';

    /**
     * In-stock units reserved while this issuance is being created.
     *
     * @var list<int>
     */
    public array $reservedInventoryUnitIds = [];

    protected $fillable = [
        'issuance_batch_id', 'reference_code', 'item_id', 'office_id', 'issued_from_office_id', 'department_id', 'requisition_id',
        'consolidated_requisition_id', 'source_endorsement_id',
        'quantity', 'returned_quantity', 'unit_cost', 'amount', 'issuance_date', 'remarks',
        'property_number', 'issued_property_numbers', 'estimated_useful_life', 'eul_expires_at',
        'useful_life_condition', 'useful_life_condition_note', 'useful_life_condition_reported_at',
        'received_from_name',
        'custodian_printed_name', 'accounting_staff_printed_name',
        'custodian_designation', 'issued_to_designation', 'received_by_printed_name',
        'issued_by', 'issued_to',
        'custody_ended_at', 'custody_end_type', 'custody_end_reference',
    ];

    protected function casts(): array
    {
        return [
            'issuance_date' => 'date',
            'eul_expires_at' => 'date',
            'useful_life_condition_reported_at' => 'datetime',
            'custody_ended_at' => 'datetime',
            'unit_cost' => 'decimal:2',
            'amount' => 'decimal:2',
            'issued_property_numbers' => 'array',
            'returned_quantity' => 'integer',
        ];
    }

    /**
     * Inventory item / property numbers of every unit issued on this slip (ICS/PAR).
     *
     * @return list<string>
     */
    public function issuedPropertyNumbers(): array
    {
        $numbers = collect($this->issued_property_numbers ?? [])
            ->map(fn (mixed $number): string => trim((string) $number))
            ->filter()
            ->values();

        if ($numbers->isEmpty() && filled($this->property_number)) {
            return [(string) $this->property_number];
        }

        return $numbers->all();
    }

    public function issuedPropertyNumbersLabel(): string
    {
        return implode(', ', $this->issuedPropertyNumbers());
    }

    /**
     * Quantity still in the employee's custody (issued minus returned/disposed through property actions).
     */
    public function remainingQuantity(): int
    {
        return max(0, (int) $this->quantity - (int) $this->returned_quantity);
    }

    protected static function booted(): void
    {
        static::saving(function (Issuance $issuance): void {
            static::applyPricingFromAcquisitions($issuance);
        });
    }

    protected static function applyPricingFromAcquisitions(Issuance $issuance): void
    {
        if ($issuance->item_id && $issuance->unit_cost === null) {
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

    public function controlNumber(): ?string
    {
        if ($this->relationLoaded('batch') && filled($this->batch?->reference_code)) {
            return (string) $this->batch->reference_code;
        }

        if ($this->issuance_batch_id !== null && ! $this->relationLoaded('batch')) {
            $this->loadMissing('batch');
            if (filled($this->batch?->reference_code)) {
                return (string) $this->batch->reference_code;
            }
        }

        return filled($this->reference_code) ? (string) $this->reference_code : null;
    }

    /**
     * @return Collection<int, Issuance>
     */
    public function batchLines(): Collection
    {
        if ($this->issuance_batch_id === null) {
            return collect([$this]);
        }

        if ($this->trashed()) {
            return self::onlyTrashed()
                ->where('issuance_batch_id', $this->issuance_batch_id)
                ->with([
                    'item',
                    'office',
                    'department',
                    'issuedBy',
                    'issuedTo',
                    'requisition',
                ])
                ->orderBy('id')
                ->get();
        }

        $this->loadMissing([
            'batch.lines.item',
            'batch.lines.office',
            'batch.lines.department',
            'batch.lines.issuedBy',
            'batch.lines.issuedTo',
            'batch.lines.requisition',
        ]);

        $lines = $this->batch?->lines;

        if ($lines === null || $lines->isEmpty()) {
            return collect([$this]);
        }

        return $lines->sortBy('id')->values();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IssuanceBatch::class, 'issuance_batch_id');
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

    public function consolidatedRequisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class, 'consolidated_requisition_id');
    }

    public function sourceEndorsement(): BelongsTo
    {
        return $this->belongsTo(RequisitionSourceEndorsement::class, 'source_endorsement_id');
    }

    public function isEmployeeDirectIssuance(): bool
    {
        return $this->consolidated_requisition_id !== null;
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function issuedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_to');
    }

    public function inventoryUnits(): HasMany
    {
        return $this->hasMany(InventoryUnit::class)->orderBy('id');
    }

    /**
     * Executed return lines (each carries the SC's receive outcome) against this issuance.
     *
     * @return HasMany<PropertyActionRequestLine, $this>
     */
    public function executedReturnLines(): HasMany
    {
        return $this->hasMany(PropertyActionRequestLine::class)
            ->whereNotNull('outcome')
            ->orderBy('id');
    }

    /**
     * @return HasMany<UsefulLifeExtension, $this>
     */
    public function usefulLifeExtensions(): HasMany
    {
        return $this->hasMany(UsefulLifeExtension::class);
    }
}
