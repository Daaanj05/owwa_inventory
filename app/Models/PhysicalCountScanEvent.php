<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhysicalCountScanEvent extends Model
{
    protected $fillable = [
        'physical_count_session_id',
        'property_number',
        'result',
        'physical_count_line_id',
        'scanned_by',
        'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PhysicalCountSession::class, 'physical_count_session_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PhysicalCountLine::class, 'physical_count_line_id');
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }
}
