<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemHealthSnapshot extends Model
{
    protected $fillable = [
        'captured_at',
        'open_sessions',
        'active_sessions',
        'laravel_sessions',
        'pending_jobs',
        'failed_jobs',
        'checks_ok',
        'checks_json',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'checks_ok' => 'boolean',
            'checks_json' => 'array',
        ];
    }
}
