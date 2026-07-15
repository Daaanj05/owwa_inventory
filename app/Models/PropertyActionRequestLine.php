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
        'disposal_id',
        'transfer_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
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
}
