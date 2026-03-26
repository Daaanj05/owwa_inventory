<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Disposal extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference_code', 'item_id', 'office_id', 'quantity',
        'disposal_date', 'reason', 'disposal_type', 'remarks',
        'property_number', 'acquisition_cost',
        'official_receipt_no', 'sale_date', 'sale_amount',
        'custodian_printed_name', 'approved_by_printed_name',
        'inspection_officer_printed_name', 'witness_printed_name',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'disposal_date' => 'date',
            'sale_date' => 'date',
            'sale_amount' => 'decimal:2',
            'acquisition_cost' => 'decimal:2',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
