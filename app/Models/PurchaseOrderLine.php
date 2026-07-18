<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'acquisition_paperwork_line_id',
        'item_id',
        'description',
        'unit',
        'pr_quantity',
        'po_quantity',
        'is_ordered',
        'unit_cost',
        'amount',
        'line_remarks',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'pr_quantity' => 'integer',
            'po_quantity' => 'integer',
            'is_ordered' => 'boolean',
            'unit_cost' => 'decimal:2',
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PurchaseOrderLine $line): void {
            if (! $line->is_ordered) {
                $line->po_quantity = 0;
                $line->unit_cost = null;
                $line->amount = null;

                return;
            }

            if ($line->unit_cost !== null && $line->po_quantity > 0) {
                $line->amount = round((float) $line->unit_cost * $line->po_quantity, 2);
            } else {
                $line->amount = null;
            }
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseRequestLine(): BelongsTo
    {
        return $this->belongsTo(AcquisitionPaperworkLine::class, 'acquisition_paperwork_line_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function stockNumber(): string
    {
        $this->loadMissing('item.category');

        if ($this->item === null) {
            return '';
        }

        return (string) (app(\App\Services\CatalogAssetNumberService::class)->catalogIdentifierForItem($this->item) ?? '');
    }
}
