<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UserActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'user_log_id',
        'action',
        'subject_type',
        'subject_id',
        'summary',
        'properties',
        'ip_address',
        'panel',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function userLog(): BelongsTo
    {
        return $this->belongsTo(UserLog::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
