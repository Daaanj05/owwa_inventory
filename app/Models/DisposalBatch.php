<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DisposalBatch extends Model
{
    use LogsUserActivity;

    protected $fillable = [
        'category_slug',
        'reference_code',
        'office_id',
        'department_id',
        'disposal_date',
        'disposal_type',
        'disposal_mode',
        'remarks',
        'recorded_by',
        'custodian_printed_name',
        'accountable_officer_designation',
        'accountable_officer_station',
        'approved_by_printed_name',
        'immediate_supervisor_printed_name',
        'inspection_officer_printed_name',
        'witness_printed_name',
    ];

    protected function casts(): array
    {
        return [
            'disposal_date' => 'date',
        ];
    }

    /**
     * @return HasMany<Disposal, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(Disposal::class, 'disposal_batch_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
