<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Office extends Model
{
    use HasFactory;

    protected $fillable = ['fiscal_year_id', 'name', 'code', 'fund_cluster', 'is_satellite', 'address'];

    protected function casts(): array
    {
        return [
            'is_satellite' => 'boolean',
            'archived_at'  => 'datetime',
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeForFiscalYear(Builder $query, ?int $fiscalYearId): Builder
    {
        if ($fiscalYearId !== null) {
            $query->where('fiscal_year_id', $fiscalYearId);
        }

        return $query;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
