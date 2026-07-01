<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'approved_by_name',
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
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AcquisitionPaperwork $paperwork): void {
            if (blank($paperwork->phase)) {
                $paperwork->phase = self::PHASE_PR;
            }

            if (blank($paperwork->reference_code)) {
                $paperwork->reference_code = 'AP-'.now()->format('Ymd').'-'.str_pad(
                    (string) (static::query()->whereDate('created_at', now()->toDateString())->count() + 1),
                    4,
                    '0',
                    STR_PAD_LEFT
                );
            }

            if (blank($paperwork->recorded_by) && auth()->id()) {
                $paperwork->recorded_by = auth()->id();
            }
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

    public function acquisitions(): HasMany
    {
        return $this->hasMany(Acquisition::class);
    }

    public function isPrApproved(): bool
    {
        return $this->pr_status === self::STATUS_APPROVED;
    }

    public function isPoApproved(): bool
    {
        return $this->po_status === self::STATUS_APPROVED;
    }

    public function isIarApproved(): bool
    {
        return $this->iar_status === self::STATUS_APPROVED;
    }

    public function isReceived(): bool
    {
        return $this->received_at !== null;
    }

    public function workflowStatusLabel(): string
    {
        if ($this->isReceived()) {
            return 'Received';
        }

        if ($this->iar_status === self::STATUS_PENDING_APPROVAL) {
            return 'IAR pending approval';
        }

        if ($this->isIarApproved()) {
            return 'Ready for custodian receipt';
        }

        if ($this->iar_status === self::STATUS_DRAFT && $this->isPoApproved()) {
            return 'IAR in progress';
        }

        if ($this->po_status === self::STATUS_PENDING_APPROVAL) {
            return 'PO pending approval';
        }

        if ($this->isPoApproved()) {
            return 'PO approved';
        }

        if ($this->po_status === self::STATUS_DRAFT && $this->isPrApproved()) {
            return 'PO in progress';
        }

        if ($this->pr_status === self::STATUS_PENDING_APPROVAL) {
            return 'PR pending approval';
        }

        if ($this->isPrApproved()) {
            return 'PR approved';
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

        if (blank($this->requesting_office_id)) {
            $missing[] = 'office / section';
        }

        if ($this->lines()->count() === 0) {
            $missing[] = 'at least one line item';
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
