<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyActionRequestLine extends Model
{
    protected $fillable = [
        'property_action_request_id',
        'issuance_id',
        'inventory_unit_id',
        'quantity',
        'disposal_id',
        'transfer_id',
        'outcome',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function propertyActionRequest(): BelongsTo
    {
        return $this->belongsTo(PropertyActionRequest::class);
    }

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class);
    }

    public function inventoryUnit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class);
    }

    public function disposal(): BelongsTo
    {
        return $this->belongsTo(Disposal::class);
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    /**
     * The unit this line sent to repair is still waiting for the Supply Custodian (not re-issued and repaired again elsewhere).
     */
    public function isUnderRepair(): bool
    {
        return $this->outcome === PropertyActionRequest::OUTCOME_REPAIR
            && $this->inventoryUnit?->status === InventoryUnit::STATUS_UNDER_REPAIR
            && (int) $this->inventoryUnit->repair_source_issuance_id === (int) $this->issuance_id;
    }

    public function outcomeLabel(): string
    {
        return [
            'returned' => 'Returned to stock',
            'disposed' => 'Disposed',
            'under_repair' => 'Under repair',
            'repaired_stock' => 'Repaired — in stock',
            'repaired_reissued' => 'Repaired — re-issued',
            'unrepairable' => 'Unrepairable — disposed',
        ][$this->outcomeState()] ?? '—';
    }

    public function outcomeColor(): string
    {
        return match ($this->outcomeState()) {
            'under_repair' => 'warning',
            'disposed', 'unrepairable' => 'danger',
            null => 'gray',
            default => 'success',
        };
    }

    /**
     * Receive outcome of this unit; repair lines follow the unit to its current result.
     */
    protected function outcomeState(): ?string
    {
        if ($this->outcome !== PropertyActionRequest::OUTCOME_REPAIR) {
            return match ($this->outcome) {
                PropertyActionRequest::OUTCOME_RETURN_TO_STOCK => 'returned',
                PropertyActionRequest::OUTCOME_DISPOSE => 'disposed',
                default => null,
            };
        }

        if ($this->isUnderRepair()) {
            return 'under_repair';
        }

        return match ($this->inventoryUnit?->status) {
            InventoryUnit::STATUS_UNDER_REPAIR => 'repaired_reissued',
            InventoryUnit::STATUS_IN_STOCK => 'repaired_stock',
            InventoryUnit::STATUS_ISSUED => 'repaired_reissued',
            InventoryUnit::STATUS_DISPOSED => 'unrepairable',
            default => 'under_repair',
        };
    }
}
