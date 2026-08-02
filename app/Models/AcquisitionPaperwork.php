<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use App\Services\ReferenceCodeService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AcquisitionPaperwork extends Model
{
    use HasFactory, LogsUserActivity;

    public const PHASE_PR = 'pr';

    public const PHASE_PO = 'po';

    public const PHASE_IAR = 'iar';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    protected $table = 'acquisition_paperwork';

    protected $fillable = [
        'reference_code',
        'item_category_id',
        'office_id',
        'requesting_office_id',
        'department_id',
        'recorded_by',
        'phase',
        'pr_status',
        'po_status',
        'iar_status',
        'pr_number',
        'po_number',
        'iar_number',
        'pr_date',
        'po_date',
        'iar_date',
        'purpose',
        'supplier',
        'requested_by_name',
        'requested_by_designation',
        'approved_by_name',
        'approved_by_designation',
        'inspection_officer_name',
        'custodian_name',
        'po_data',
        'iar_data',
        'remarks',
        'pr_completed_at',
        'po_completed_at',
        'iar_completed_at',
        'pr_submitted_at',
        'po_submitted_at',
        'iar_submitted_at',
        'received_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'pr_date' => 'date',
            'po_date' => 'date',
            'iar_date' => 'date',
            'po_data' => 'array',
            'iar_data' => 'array',
            'pr_completed_at' => 'datetime',
            'po_completed_at' => 'datetime',
            'iar_completed_at' => 'datetime',
            'pr_submitted_at' => 'datetime',
            'po_submitted_at' => 'datetime',
            'iar_submitted_at' => 'datetime',
            'received_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AcquisitionPaperwork $paperwork): void {
            if (blank($paperwork->phase)) {
                $paperwork->phase = self::PHASE_PR;
            }

            if (blank($paperwork->reference_code)) {
                $paperwork->reference_code = app(ReferenceCodeService::class)->forAcquisitionPaperwork();
            }

            if (blank($paperwork->pr_number)) {
                $paperwork->pr_number = app(ReferenceCodeService::class)->forAcquisitionPaperworkPr();
            }

            if (blank($paperwork->recorded_by) && auth()->id()) {
                $paperwork->recorded_by = auth()->id();
            }
        });

        static::saved(function (AcquisitionPaperwork $paperwork): void {
            ProcurementSignatoryName::remember(ProcurementSignatoryName::ROLE_REQUESTED, $paperwork->requested_by_name);
            ProcurementSignatoryName::remember(ProcurementSignatoryName::ROLE_REQUESTED_DESIGNATION, $paperwork->requested_by_designation);
            ProcurementSignatoryName::remember(ProcurementSignatoryName::ROLE_APPROVED, $paperwork->approved_by_name);
            ProcurementSignatoryName::remember(ProcurementSignatoryName::ROLE_APPROVED_DESIGNATION, $paperwork->approved_by_designation);
        });
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function requestingOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'requesting_office_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
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
        return $this->hasMany(AcquisitionPaperworkLine::class);
    }

    public function requisitions(): BelongsToMany
    {
        return $this->belongsToMany(Requisition::class, 'acquisition_paperwork_requisition')
            ->withTimestamps();
    }

    public function acquisitions(): HasMany
    {
        return $this->hasMany(Acquisition::class);
    }

    public function purchaseOrder(): HasOne
    {
        return $this->hasOne(PurchaseOrder::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isPrEditable(): bool
    {
        return $this->pr_status === self::STATUS_DRAFT && ! $this->isArchived() && ! $this->isReceived();
    }

    public function isPrPendingApproval(): bool
    {
        return $this->pr_status === self::STATUS_PENDING_APPROVAL;
    }

    public function canCreatePurchaseOrder(): bool
    {
        return $this->isPrApproved()
            && ! $this->isArchived()
            && ! $this->purchaseOrder()->exists();
    }

    public function isPrApproved(): bool
    {
        return $this->pr_status === self::STATUS_APPROVED;
    }

    public function isPoApproved(): bool
    {
        return $this->purchaseOrder?->isApproved()
            ?? ($this->po_status === self::STATUS_APPROVED);
    }

    public function isIarApproved(): bool
    {
        return $this->purchaseOrder?->inspectionAcceptanceReport?->isApproved()
            ?? ($this->iar_status === self::STATUS_APPROVED);
    }

    public function isReceived(): bool
    {
        return $this->received_at !== null
            || ($this->purchaseOrder?->inspectionAcceptanceReport?->isReceived() ?? false);
    }

    public function workflowStatusLabel(): string
    {
        if ($this->isArchived()) {
            return 'Archived';
        }

        if ($this->isReceived()) {
            return 'Received';
        }

        $iar = $this->purchaseOrder?->inspectionAcceptanceReport;
        if ($iar?->isPendingApproval()) {
            return 'IAR pending approval';
        }

        if ($iar?->isApproved()) {
            return 'Ready for custodian receipt';
        }

        if ($iar?->isDraft()) {
            return 'IAR in progress';
        }

        $po = $this->purchaseOrder;
        if ($po?->isPendingApproval()) {
            return 'PO pending approval';
        }

        if ($po?->isApproved()) {
            return 'PO approved';
        }

        if ($po?->isDraft()) {
            return 'PO in progress';
        }

        if ($this->pr_status === self::STATUS_PENDING_APPROVAL) {
            return 'PR pending approval';
        }

        if ($this->isPrApproved()) {
            return 'PR approved — ready for PO';
        }

        return 'PR in progress';
    }

    public function phaseStatusLabel(string $phase): string
    {
        $status = match ($phase) {
            self::PHASE_PO => $this->po_status,
            self::PHASE_IAR => $this->iar_status,
            default => $this->pr_status,
        };

        return match ($status) {
            self::STATUS_PENDING_APPROVAL => 'Pending approval',
            self::STATUS_APPROVED => 'Approved',
            default => 'Draft',
        };
    }

    public function templateSlug(): string
    {
        return $this->itemCategory?->getTemplateSlug() ?? 'consumables';
    }

    public function isPhase(string $phase): bool
    {
        return $this->phase === $phase;
    }

    public function phaseLabel(): string
    {
        return match ($this->phase) {
            self::PHASE_PO => 'Purchase order',
            self::PHASE_IAR => 'Inspection & acceptance',
            default => 'Purchase request',
        };
    }

    public function totalAmount(): float
    {
        return (float) $this->lines()->sum('amount');
    }

    /**
     * Compact one-line label for selected value in Pickers.
     */
    public function purchaseOrderPickerSummary(): string
    {
        $number = $this->pr_number ?: $this->reference_code ?: 'PR';
        $purpose = filled($this->purpose) ? (string) str($this->purpose)->limit(50) : 'No purpose';

        return trim("{$number} — {$purpose}");
    }

    /**
     * Rich HTML option label for the Create PO → Choose PR select.
     */
    public function purchaseOrderPickerOptionHtml(): string
    {
        $number = e((string) ($this->pr_number ?: $this->reference_code ?: 'PR'));
        $purpose = filled($this->purpose)
            ? e((string) str($this->purpose)->limit(90))
            : 'No purpose';
        $date = e($this->pr_date?->format('M d, Y') ?? 'No date');
        $office = e((string) (
            $this->requestingOffice?->name
            ?? $this->office?->name
            ?? 'No office'
        ));

        $lineCount = $this->relationLoaded('lines')
            ? $this->lines->count()
            : $this->lines()->count();
        $total = $this->relationLoaded('lines')
            ? (float) $this->lines->sum(fn (AcquisitionPaperworkLine $line): float => (float) ($line->amount ?? 0))
            : $this->totalAmount();
        $linesLabel = $lineCount === 1 ? '1 line' : "{$lineCount} lines";
        $totalLabel = $total > 0 ? '₱'.number_format($total, 2) : 'No amount';

        return <<<HTML
<div class="owwa-doc-picker-option">
    <span class="owwa-doc-picker-option__title">{$number}</span>
    <span class="owwa-doc-picker-option__purpose">{$purpose}</span>
    <span class="owwa-doc-picker-option__meta">{$date} · {$office} · {$linesLabel} · {$totalLabel}</span>
</div>
HTML;
    }

    /**
     * @return array<int, string>
     */
    public function missingPrFields(): array
    {
        $missing = [];

        foreach (['pr_date', 'purpose'] as $field) {
            if (blank($this->{$field})) {
                $missing[] = str_replace('_', ' ', $field);
            }
        }

        if (filled($this->purpose) && mb_strlen(trim((string) $this->purpose)) < 8) {
            $missing[] = 'purpose (at least 8 characters)';
        }

        if (blank($this->requesting_office_id)) {
            $missing[] = 'office / section';
        }

        if ($this->lines()->count() === 0) {
            $missing[] = 'at least one line item';
        }

        return $missing;
    }

    /**
     * @return array<int, string>
     */
    public function missingPoFields(): array
    {
        $missing = [];

        foreach (['po_date', 'supplier'] as $field) {
            if (blank($this->{$field})) {
                $missing[] = str_replace('_', ' ', $field);
            }
        }

        $linesWithoutCost = $this->lines()->where(function ($query): void {
            $query->whereNull('unit_cost')->orWhere('unit_cost', '<=', 0);
        })->count();

        if ($linesWithoutCost > 0) {
            $missing[] = 'unit cost on all lines';
        }

        return $missing;
    }

    /**
     * @return array<int, string>
     */
    public function missingIarFields(): array
    {
        $missing = [];

        foreach (['iar_date', 'inspection_officer_name', 'custodian_name'] as $field) {
            if (blank($this->{$field})) {
                $missing[] = str_replace('_', ' ', $field);
            }
        }

        return $missing;
    }
}
