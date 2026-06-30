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

    protected $fillable = [
        'reference_code', 'item_id', 'from_office_id', 'to_office_id',
        'quantity', 'transfer_date', 'transfer_type', 'transfer_type_other',
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
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
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
}
