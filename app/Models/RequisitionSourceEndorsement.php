<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionSourceEndorsement extends Model
{
    protected $fillable = [
        'consolidated_requisition_id',
        'source_requisition_id',
        'requisition_item_id',
        'requested_by_user_id',
        'item_id',
        'requested_quantity',
        'endorsed_quantity',
        'employee_remarks',
    ];

    public function consolidatedRequisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class, 'consolidated_requisition_id');
    }

    public function sourceRequisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class, 'source_requisition_id');
    }

    public function requisitionItem(): BelongsTo
    {
        return $this->belongsTo(RequisitionItem::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
