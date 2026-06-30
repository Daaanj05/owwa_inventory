<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsefulLifeExtension extends Model
{
    protected $fillable = [
        'issuance_id',
        'previous_eul',
        'new_eul',
        'previous_expires_at',
        'new_expires_at',
        'reason',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_expires_at' => 'date',
            'new_expires_at' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
