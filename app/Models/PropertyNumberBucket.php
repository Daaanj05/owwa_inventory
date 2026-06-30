<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyNumberBucket extends Model
{
    protected $fillable = [
        'bucket_key',
        'next_sequence',
    ];

    protected function casts(): array
    {
        return [
            'next_sequence' => 'integer',
        ];
    }
}
