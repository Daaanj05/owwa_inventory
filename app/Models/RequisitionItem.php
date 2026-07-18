<?php

namespace App\Models;

use App\Support\RequisitionLineFulfillmentState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RequisitionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'requisition_id',
        'item_id',
        'quantity',
        'stock_at_request',
        'stock_available',
        'quantity_issued',
        'issue_remarks',
        'remarks',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function acquisitionPaperworkLines(): BelongsToMany
    {
        return $this->belongsToMany(
            AcquisitionPaperworkLine::class,
            'acquisition_paperwork_line_requisition_item',
        )->withPivot('quantity')->withTimestamps();
    }

    public function isBackordered(): bool
    {
        if ($this->stock_at_request === null) {
            return false;
        }

        return (int) $this->stock_at_request < (int) $this->quantity;
    }

    public function fulfillmentState(): string
    {
        $requested = (int) $this->quantity;
        $issued = (int) ($this->quantity_issued ?? 0);

        if ($issued >= $requested && $requested > 0) {
            return RequisitionLineFulfillmentState::FULLY_ISSUED;
        }

        if ($issued > 0) {
            return RequisitionLineFulfillmentState::PARTIALLY_ISSUED;
        }

        if ($this->isBackordered()) {
            return RequisitionLineFulfillmentState::BACKORDERED;
        }

        return RequisitionLineFulfillmentState::IN_STOCK;
    }

    public function fulfillmentStateLabel(): string
    {
        return RequisitionLineFulfillmentState::label($this->fulfillmentState());
    }
}
