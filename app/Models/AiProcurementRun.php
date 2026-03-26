<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiProcurementRun extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'ran_at',
        'period_from',
        'period_to',
        'summary',
        'raw_response',
        'status',
        'created_by',
    ];

    protected $casts = [
        'ran_at'      => 'datetime',
        'period_from' => 'date',
        'period_to'   => 'date',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(AiProcurementItem::class, 'run_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

