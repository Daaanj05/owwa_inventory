<?php

namespace App\Models;

use App\Services\InventoryPlanLineStatusService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhysicalInventoryPlanLine extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_OVERDUE = 'overdue';

    protected $fillable = [
        'physical_inventory_plan_id',
        'office_id',
        'item_category_id',
        'planned_date',
        'physical_count_session_id',
        'last_reminder_type',
        'last_reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'last_reminded_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PhysicalInventoryPlan::class, 'physical_inventory_plan_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function itemCategory(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
    }

    public function physicalCountSession(): BelongsTo
    {
        return $this->belongsTo(PhysicalCountSession::class);
    }

    public function computedStatus(): string
    {
        return app(InventoryPlanLineStatusService::class)->statusForLine($this);
    }

    public function isOverdue(): bool
    {
        return $this->physical_count_session_id === null
            && $this->planned_date !== null
            && $this->planned_date->isPast()
            && ! $this->planned_date->isToday();
    }

    public function canStartCount(): bool
    {
        return $this->physical_count_session_id === null && ! $this->isOverdue();
    }
}
