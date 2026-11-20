<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PhysicalInventoryPlan extends Model
{
    use HasFactory, LogsUserActivity, SoftDeletes;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const DISPLAY_OVERDUE = 'overdue';

    protected $fillable = [
        'reference_code',
        'title',
        'period_label',
        'cut_off_date',
        'status',
        'item_category_id',
        'committee_chair_name',
        'property_officer_name',
        'accounting_officer_name',
        'approved_at',
        'coa_submitted_at',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'cut_off_date' => 'date',
            'coa_submitted_at' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PhysicalInventoryPlan $plan): void {
            if (blank($plan->reference_code)) {
                $plan->reference_code = 'IP-'.now()->format('Ymd').'-'.str_pad(
                    (string) (static::query()->whereDate('created_at', now()->toDateString())->count() + 1),
                    4,
                    '0',
                    STR_PAD_LEFT
                );
            }

            if (blank($plan->recorded_by) && auth()->id()) {
                $plan->recorded_by = auth()->id();
            }

            if (blank($plan->status)) {
                $plan->status = self::STATUS_SCHEDULED;
            }
        });
    }

    public function itemCategory(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PhysicalInventoryPlanLine::class);
    }

    public function workflowEmailNotifications(): HasMany
    {
        return $this->hasMany(WorkflowEmailNotification::class)->latest();
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isEditable(): bool
    {
        if ($this->isCompleted() || $this->status === self::STATUS_CANCELLED) {
            return false;
        }

        $this->loadMissing('lines');

        return $this->lines->every(
            fn (PhysicalInventoryPlanLine $line): bool => $line->physical_count_session_id === null
        );
    }

    public function hasOverdueLines(): bool
    {
        $this->loadMissing('lines');

        return $this->lines->contains(
            fn (PhysicalInventoryPlanLine $line): bool => $line->isOverdue()
        );
    }

    public function displayStatus(): string
    {
        if ($this->isScheduled() && $this->hasOverdueLines()) {
            return self::DISPLAY_OVERDUE;
        }

        return (string) $this->status;
    }

    public function statusLabel(?string $status = null): string
    {
        return match ($status ?? $this->displayStatus()) {
            self::STATUS_SCHEDULED => 'Scheduled',
            self::DISPLAY_OVERDUE => 'Overdue',
            self::STATUS_IN_PROGRESS => 'In progress',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst((string) ($status ?? $this->status)),
        };
    }

    public function statusColor(?string $status = null): string
    {
        return match ($status ?? $this->displayStatus()) {
            self::STATUS_COMPLETED => 'success',
            self::STATUS_IN_PROGRESS => 'warning',
            self::DISPLAY_OVERDUE, self::STATUS_CANCELLED => 'danger',
            default => 'gray',
        };
    }

    /**
     * @return array{completed: int, total: int}
     */
    public function progressCounts(): array
    {
        $this->loadMissing('lines.physicalCountSession');

        $total = $this->lines->count();
        $completed = $this->lines->filter(
            fn (PhysicalInventoryPlanLine $line): bool => $line->physicalCountSession?->isComplete() ?? false
        )->count();

        return ['completed' => $completed, 'total' => $total];
    }

    public function progressPercent(): int
    {
        $counts = $this->progressCounts();

        if ($counts['total'] === 0) {
            return 0;
        }

        return (int) min(100, round(($counts['completed'] / $counts['total']) * 100));
    }
}
