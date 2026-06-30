<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Distribution extends Model
{
    use HasFactory, LogsUserActivity;

    protected $fillable = [
        'office_id',
        'department_id',
        'requisition_id',
        'item_id',
        'quantity',
        'distributed_to',
        'distributed_by',
        'distribution_date',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'distribution_date' => 'date',
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function distributedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributed_to');
    }

    public function distributedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributed_by');
    }
}
