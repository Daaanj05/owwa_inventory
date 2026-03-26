<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'is_default',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_default' => 'boolean',
    ];

    public function containsDate(Carbon $date): bool
    {
        return $date->between($this->start_date, $this->end_date);
    }

    public function offices(): HasMany
    {
        return $this->hasMany(Office::class, 'fiscal_year_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'fiscal_year_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'fiscal_year_id');
    }
}

