<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Office extends Model
{
    use HasFactory, LogsUserActivity;

    protected $fillable = [
        'name',
        'code',
        'fund_cluster',
        'is_satellite',
        'is_regional_supply',
        'address',
        'supply_custodian_name',
        'supply_custodian_designation',
        'authorized_officer_name',
        'authorized_officer_designation',
        'accountable_officer_name',
        'accountable_officer_designation',
        'inspection_officer_name',
    ];

    protected function casts(): array
    {
        return [
            'is_satellite' => 'boolean',
            'is_regional_supply' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Office $office): void {
            if ($office->is_regional_supply) {
                $office->is_satellite = false;
            }
        });

        static::saved(function (Office $office): void {
            if (! $office->is_regional_supply) {
                return;
            }

            DB::transaction(function () use ($office): void {
                static::query()
                    ->whereKeyNot($office->getKey())
                    ->where('is_regional_supply', true)
                    ->update(['is_regional_supply' => false]);
            });
        });
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
