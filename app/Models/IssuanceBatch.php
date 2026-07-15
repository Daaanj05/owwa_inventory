<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IssuanceBatch extends Model
{
    use LogsUserActivity;

    protected $fillable = [
        'category_slug',
        'reference_code',
        'requisition_id',
        'office_id',
        'department_id',
        'issuance_date',
        'remarks',
        'issued_by',
        'issued_to',
        'custodian_printed_name',
        'custodian_designation',
        'issued_to_designation',
        'accounting_staff_printed_name',
        'received_from_name',
    ];

    protected function casts(): array
    {
        return [
            'issuance_date' => 'date',
        ];
    }

    public function categorySlug(): string
    {
        return (string) $this->category_slug;
    }

    /**
     * @return HasMany<Issuance, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(Issuance::class, 'issuance_batch_id');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function issuedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_to');
    }
}
