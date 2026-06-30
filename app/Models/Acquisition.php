<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Acquisition extends Model
{
    use HasFactory, LogsUserActivity, SoftDeletes;

    protected $fillable = [
        'reference_code', 'item_id', 'office_id', 'quantity', 'unit_cost',
        'acquisition_date', 'source', 'remarks', 'recorded_by',
        'acquisition_paperwork_id', 'acquisition_paperwork_line_id',
    ];

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'unit_cost' => 'decimal:2',
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

    public function inventoryUnits(): HasMany
    {
        return $this->hasMany(InventoryUnit::class);
    }

    public function acquisitionPaperwork(): BelongsTo
    {
        return $this->belongsTo(AcquisitionPaperwork::class);
    }

    public function acquisitionPaperworkLine(): BelongsTo
    {
        return $this->belongsTo(AcquisitionPaperworkLine::class);
    }
}
