<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowEmailNotification extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'user_id',
        'requisition_id',
        'property_action_request_id',
        'physical_inventory_plan_id',
        'physical_inventory_plan_line_id',
        'title',
        'body',
        'status',
        'failure_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function propertyActionRequest(): BelongsTo
    {
        return $this->belongsTo(PropertyActionRequest::class);
    }

    public function physicalInventoryPlan(): BelongsTo
    {
        return $this->belongsTo(PhysicalInventoryPlan::class);
    }

    public function physicalInventoryPlanLine(): BelongsTo
    {
        return $this->belongsTo(PhysicalInventoryPlanLine::class);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_QUEUED => 'Queued',
            self::STATUS_SENT => 'Sent',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_SKIPPED => 'Skipped',
            default => $status,
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_QUEUED => 'info',
            self::STATUS_SENT => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_SKIPPED => 'gray',
            default => 'gray',
        };
    }
}
