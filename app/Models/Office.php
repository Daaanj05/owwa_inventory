<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Office extends Model
{
    use HasFactory, LogsUserActivity;

    protected $fillable = ['name', 'code', 'fund_cluster', 'is_satellite', 'address',
        'supply_custodian_name', 'supply_custodian_designation',
        'authorized_officer_name', 'authorized_officer_designation',
        'accountable_officer_name', 'accountable_officer_designation',
        'inspection_officer_name',
    ];

    protected function casts(): array
    {
        return [
            'is_satellite' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
