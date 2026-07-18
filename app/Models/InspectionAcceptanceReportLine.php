<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionAcceptanceReportLine extends Model
{
    protected $fillable = [
        'inspection_acceptance_report_id',
        'purchase_order_line_id',
        'acquisition_paperwork_line_id',
        'item_id',
        'description',
        'unit',
        'pr_quantity',
        'po_quantity',
        'iar_quantity',
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
            'iar_quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (InspectionAcceptanceReportLine $line): void {
            if ($line->unit_cost !== null && $line->iar_quantity > 0) {
                $line->amount = round((float) $line->unit_cost * $line->iar_quantity, 2);
            } else {
                $line->amount = null;
            }
        });
    }

    public function inspectionAcceptanceReport(): BelongsTo
    {
        return $this->belongsTo(InspectionAcceptanceReport::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
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
