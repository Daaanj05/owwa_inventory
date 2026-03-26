<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProcurementItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_id',
        'section',
        'priority',
        'item_name',
        'item_id',
        'office_name',
        'office_id',
        'current_stock',
        'avg_monthly_usage',
        'months_cover',
        'suggested_qty_min',
        'suggested_qty_max',
        'reason',
        'include_in_request',
    ];

    protected $casts = [
        'include_in_request' => 'boolean',
        'avg_monthly_usage'  => 'float',
        'months_cover'       => 'float',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiProcurementRun::class, 'run_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}

