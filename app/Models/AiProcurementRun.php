<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiProcurementRun extends Model
{
    use HasFactory, LogsUserActivity, SoftDeletes;

    protected $fillable = [
        'ran_at',
        'period_from',
        'period_to',
        'summary',
        'raw_response',
        'status',
        'error_message',
        'created_by',
    ];

    protected $casts = [
        'ran_at' => 'datetime',
        'period_from' => 'date',
        'period_to' => 'date',
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
