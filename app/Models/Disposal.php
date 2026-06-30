<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Disposal extends Model
{
    use HasFactory, LogsUserActivity, SoftDeletes;

    protected $fillable = [
        'reference_code', 'item_id', 'inventory_unit_id', 'office_id', 'department_id', 'place_of_storage', 'quantity',
        'disposal_date', 'reason', 'disposal_type', 'disposal_mode', 'wmr_inspection_item_no',
        'remarks', 'property_number', 'acquisition_cost', 'circumstances', 'par_issuance_id',
        'police_notified', 'police_station', 'police_notified_date', 'property_status',
        'official_receipt_no', 'sale_date', 'sale_amount',
        'custodian_printed_name', 'accountable_officer_designation', 'accountable_officer_station',
        'approved_by_printed_name', 'immediate_supervisor_printed_name',
        'inspection_officer_printed_name', 'witness_printed_name',
        'gov_id_type', 'gov_id_no', 'gov_id_date_issued', 'iirup_disposal_mode',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'disposal_date' => 'date',
            'sale_date' => 'date',
            'police_notified_date' => 'date',
            'gov_id_date_issued' => 'date',
            'police_notified' => 'boolean',
            'sale_amount' => 'decimal:2',
            'acquisition_cost' => 'decimal:2',
        ];
    }

    public function inventoryUnit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class);
    }

    public function parIssuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class, 'par_issuance_id');
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

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
