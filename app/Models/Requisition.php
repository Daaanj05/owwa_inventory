<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use App\Services\RequisitionFulfillmentService;
use App\Support\EmployeeRequisitionOriginalSubmission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Requisition extends Model
{
    use HasFactory, LogsUserActivity;

    public ?string $statusBeforeUpdate = null;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'reference_code', 'transaction_number', 'office_id', 'department_id', 'requested_by',
        'status', 'remarks', 'purpose', 'original_submission', 'content_edited_at',
        'approved_by', 'approved_at',
        'endorsed_at', 'endorsed_by', 'closed_at', 'fulfillment_summary',
        'compiled_into_requisition_id', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'original_submission' => 'array',
            'content_edited_at' => 'datetime',
            'approved_at' => 'datetime',
            'endorsed_at' => 'datetime',
            'closed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function endorsedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'endorsed_by');
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

    public function acquisitionPaperworks(): BelongsToMany
    {
        return $this->belongsToMany(AcquisitionPaperwork::class, 'acquisition_paperwork_requisition')
            ->withTimestamps();
    }

    public function compiledIntoRequisition(): BelongsTo
    {
        return $this->belongsTo(self::class, 'compiled_into_requisition_id');
    }

    public function sourceRequests(): HasMany
    {
        return $this->hasMany(self::class, 'compiled_into_requisition_id');
    }

    public function sourceEndorsements(): HasMany
    {
        return $this->hasMany(RequisitionSourceEndorsement::class, 'consolidated_requisition_id');
    }

    public function sourceEndorsementForItem(int $requisitionItemId): ?RequisitionSourceEndorsement
    {
        return $this->sourceEndorsements()
            ->where('requisition_item_id', $requisitionItemId)
            ->first();
    }

    public function employeeLineEndorsement(int $requisitionItemId): ?RequisitionSourceEndorsement
    {
        return RequisitionSourceEndorsement::query()
            ->where('source_requisition_id', $this->id)
            ->where('requisition_item_id', $requisitionItemId)
            ->first();
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(Distribution::class);
    }

    public function issuances(): HasMany
    {
        return $this->hasMany(Issuance::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function canEmployeeEdit(): bool
    {
        if (! $this->isEmployeeRequest() || $this->isArchived()) {
            return false;
        }

        if ($this->compiled_into_requisition_id !== null) {
            return false;
        }

        return $this->isDraft() || $this->isPendingCustodianReview();
    }

    public function canEmployeeArchive(): bool
    {
        return $this->isEmployeeRequest()
            && $this->isDraft()
            && ! $this->isArchived();
    }

    public function canEmployeeSubmit(): bool
    {
        if (! $this->isEmployeeRequest() || ! $this->isDraft() || $this->isArchived()) {
            return false;
        }

        $this->loadMissing('items');

        return $this->items->isNotEmpty();
    }

    public function hasEmployeeContentEdits(): bool
    {
        return EmployeeRequisitionOriginalSubmission::differsFromCurrent($this);
    }

    public function snapshotOriginalSubmissionIfNeeded(): void
    {
        if (filled($this->original_submission)) {
            return;
        }

        $this->forceFill([
            'original_submission' => EmployeeRequisitionOriginalSubmission::capture($this),
        ])->saveQuietly();
    }

    public function canEmployeeRestore(): bool
    {
        return $this->isEmployeeRequest()
            && $this->isArchived()
            && $this->isDraft();
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

    public function isEmployeeRequest(): bool
    {
        $this->loadMissing('requestedBy');

        return $this->requestedBy?->role === User::ROLE_EMPLOYEE;
    }

    public function displayTransactionNumber(): ?string
    {
        return $this->transaction_number;
    }

    public function displayRisNumber(): ?string
    {
        if ($this->isEmployeeRequest()) {
            $this->loadMissing('compiledIntoRequisition');

            return $this->compiledIntoRequisition?->reference_code;
        }

        return $this->reference_code;
    }

    public function displayRisPurpose(): ?string
    {
        if ($this->isEmployeeRequest()) {
            $this->loadMissing('compiledIntoRequisition');

            return $this->compiledIntoRequisition?->purpose ?? $this->purpose;
        }

        return $this->purpose;
    }

    public function displayEmployeePurpose(): ?string
    {
        return $this->purpose;
    }

    public function canExportRis(): bool
    {
        return ! $this->isEmployeeRequest() && filled($this->reference_code);
    }

    public function hasBackorderedLines(): bool
    {
        $this->loadMissing('items');

        return $this->items->contains(fn (RequisitionItem $line): bool => $line->isBackordered());
    }
}
