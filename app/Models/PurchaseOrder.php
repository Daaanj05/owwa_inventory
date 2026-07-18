<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseOrder extends Model
{
    use LogsUserActivity;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'acquisition_paperwork_id',
        'supplier_id',
        'recorded_by',
        'number',
        'status',
        'po_date',
        'supplier_name',
        'supplier_address',
        'supplier_tin',
        'mode_of_procurement',
        'place_of_delivery',
        'delivery_term',
        'date_of_delivery',
        'payment_term',
        'technical_specifications',
        'remarks',
        'submitted_at',
        'approved_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'po_date' => 'date',
            'date_of_delivery' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(AcquisitionPaperwork::class, 'acquisition_paperwork_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function orderedLines(): HasMany
    {
        return $this->lines()->where('is_ordered', true)->where('po_quantity', '>', 0);
    }

    public function inspectionAcceptanceReport(): HasOne
    {
        return $this->hasOne(InspectionAcceptanceReport::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isEditable(): bool
    {
        return $this->isDraft() && ! $this->isArchived();
    }

    public function totalAmount(): float
    {
        return (float) $this->orderedLines()->sum('amount');
    }

    public function inspectionAcceptancePickerSummary(): string
    {
        $number = $this->number ?: 'PO';
        $prNumber = $this->purchaseRequest?->pr_number ?: $this->purchaseRequest?->reference_code ?: '—';

        return trim("{$number} — PR {$prNumber}");
    }

    public function inspectionAcceptancePickerOptionHtml(): string
    {
        $number = e((string) ($this->number ?: 'PO'));
        $pr = $this->purchaseRequest;
        $prNumber = e((string) ($pr?->pr_number ?: $pr?->reference_code ?: '—'));
        $purpose = filled($pr?->purpose)
            ? e((string) str($pr->purpose)->limit(90))
            : 'No purpose';
        $date = e($this->po_date?->format('M d, Y') ?? $this->approved_at?->format('M d, Y') ?? 'No date');
        $supplier = e((string) ($this->supplier_name ?: 'No supplier'));

        $lineCount = $this->relationLoaded('lines')
            ? $this->lines->where('is_ordered', true)->where('po_quantity', '>', 0)->count()
            : $this->orderedLines()->count();
        $total = $this->relationLoaded('lines')
            ? (float) $this->lines
                ->where('is_ordered', true)
                ->where('po_quantity', '>', 0)
                ->sum(fn ($line): float => (float) ($line->amount ?? 0))
            : $this->totalAmount();
        $linesLabel = $lineCount === 1 ? '1 line' : "{$lineCount} lines";
        $totalLabel = $total > 0 ? '₱'.number_format($total, 2) : 'No amount';

        return <<<HTML
<div class="owwa-doc-picker-option">
    <span class="owwa-doc-picker-option__title">{$number} · PR {$prNumber}</span>
    <span class="owwa-doc-picker-option__purpose">{$purpose}</span>
    <span class="owwa-doc-picker-option__meta">{$date} · {$supplier} · {$linesLabel} · {$totalLabel}</span>
</div>
HTML;
    }

    /**
     * @return array<int, string>
     */
    public function missingFields(): array
    {
        $missing = [];

        foreach ([
            'po_date' => 'po date',
            'supplier_name' => 'supplier',
            'supplier_address' => 'supplier address',
            'mode_of_procurement' => 'mode of procurement',
            'place_of_delivery' => 'place of delivery',
            'technical_specifications' => 'technical specification',
        ] as $field => $label) {
            if (blank($this->{$field})) {
                $missing[] = $label;
            }
        }

        $orderedLines = $this->orderedLines()->get();

        if ($orderedLines->isEmpty()) {
            $missing[] = 'at least one ordered line item';
        }

        $linesWithoutCost = $orderedLines->filter(
            fn (PurchaseOrderLine $line): bool => $line->unit_cost === null || (float) $line->unit_cost <= 0,
        )->count();

        if ($linesWithoutCost > 0) {
            $missing[] = 'unit cost on all ordered lines';
        }

        return $missing;
    }

    public function statusLabel(): string
    {
        if ($this->isArchived()) {
            return 'Archived';
        }

        return match ($this->status) {
            self::STATUS_PENDING_APPROVAL => 'PO pending approval',
            self::STATUS_APPROVED => $this->inspectionAcceptanceReport ? 'PO approved' : 'PO approved — ready for IAR',
            default => 'PO in progress',
        };
    }

    public function templateSlug(): string
    {
        $this->loadMissing('purchaseRequest.itemCategory');

        return $this->purchaseRequest?->templateSlug() ?? 'consumables';
    }
}
