<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use App\Services\RequisitionFulfillmentService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Requisition extends Model
{
    use HasFactory, LogsUserActivity;

    public ?string $statusBeforeUpdate = null;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'reference_code', 'office_id', 'department_id', 'requested_by',
        'status', 'remarks', 'purpose', 'approved_by', 'approved_at',
        'compiled_into_requisition_id',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequisitionItem::class, 'requisition_id');
    }

    public function compiledIntoRequisition(): BelongsTo
    {
        return $this->belongsTo(self::class, 'compiled_into_requisition_id');
    }

    public function sourceRequests(): HasMany
    {
        return $this->hasMany(self::class, 'compiled_into_requisition_id');
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(Distribution::class);
    }

    public function issuances(): HasMany
    {
        return $this->hasMany(Issuance::class);
    }

    public function isPendingCustodianReview(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function hasRemainingToIssue(): bool
    {
        $this->loadMissing('items');
        $fulfillment = app(RequisitionFulfillmentService::class);

        foreach ($this->items as $line) {
            if ($fulfillment->remainingQuantity($line) > 0) {
                return true;
            }
        }

        return false;
    }

    public function canCustodianIssue(): bool
    {
        return $this->isPendingCustodianReview()
            || ($this->isAccepted() && $this->hasRemainingToIssue());
    }

    public function hasMixedCategories(): bool
    {
        $this->loadMissing('items.item.category');

        return $this->items
            ->pluck('item.item_category_id')
            ->filter()
            ->unique()
            ->count() > 1;
    }

    /**
     * @return array<int, string>
     */
    public function categoryNames(): array
    {
        $this->loadMissing('items.item.category');

        return $this->items
            ->map(fn (RequisitionItem $line): ?string => $line->item?->category?->name)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
