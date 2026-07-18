<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class InspectionAcceptanceReport extends Model
{
    use LogsUserActivity;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'purchase_order_id',
        'recorded_by',
        'number',
        'status',
        'iar_date',
        'invoice_number',
        'invoice_date',
        'date_inspected',
        'date_received',
        'inspection_officer_name',
        'custodian_name',
        'remarks',
        'submitted_at',
        'approved_at',
        'stock_received_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'iar_date' => 'date',
            'invoice_date' => 'date',
            'date_inspected' => 'date',
            'date_received' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'stock_received_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (InspectionAcceptanceReport $iar): void {
            ProcurementSignatoryName::remember(
                ProcurementSignatoryName::ROLE_INSPECTION_OFFICER,
                $iar->inspection_officer_name,
            );
            ProcurementSignatoryName::remember(
                ProcurementSignatoryName::ROLE_CUSTODIAN,
                $iar->custodian_name,
            );
        });
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InspectionAcceptanceReportLine::class)->orderBy('sort_order')->orderBy('id');
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

    public function isReceived(): bool
    {
        return $this->stock_received_at !== null;
    }

    public function isEditable(): bool
    {
        return $this->isDraft() && ! $this->isArchived() && ! $this->isReceived();
    }

    /**
     * @return array<int, string>
     */
    public function missingFields(): array
    {
        $missing = [];

        foreach ([
            'iar_date' => 'iar date',
            'invoice_number' => 'invoice no.',
            'invoice_date' => 'invoice date',
            'date_inspected' => 'date inspected',
            'date_received' => 'date received',
            'inspection_officer_name' => 'inspection officer',
            'custodian_name' => 'supply and/or property custodian',
        ] as $field => $label) {
            if (blank($this->{$field})) {
                $missing[] = $label;
            }
        }

        if (filled($this->invoice_number) && ! preg_match('/^[A-Za-z0-9]+$/', (string) $this->invoice_number)) {
            $missing[] = 'invoice no. (alphanumeric only)';
        }

        foreach (['invoice_date', 'date_inspected', 'date_received'] as $dateField) {
            if ($this->iar_date && $this->{$dateField} && ! $this->{$dateField}->greaterThan($this->iar_date)) {
                $missing[] = str_replace('_', ' ', $dateField).' must be after IAR date';
            }
        }

        if ($this->lines()->where('iar_quantity', '>', 0)->count() === 0) {
            $missing[] = 'at least one IAR quantity greater than zero';
        }

        $invalidQty = $this->lines()
            ->get()
            ->filter(fn (InspectionAcceptanceReportLine $line): bool => $line->iar_quantity < 0 || $line->iar_quantity > $line->po_quantity)
            ->count();

        if ($invalidQty > 0) {
            $missing[] = 'IAR quantity must be between 0 and PO quantity';
        }

        return $missing;
    }

    public function statusLabel(): string
    {
        if ($this->isArchived()) {
            return 'Archived';
        }

        if ($this->isReceived()) {
            return 'Received';
        }

        return match ($this->status) {
            self::STATUS_PENDING_APPROVAL => 'IAR pending approval',
            self::STATUS_APPROVED => 'Ready for custodian receipt',
            default => 'IAR in progress',
        };
    }

    public function templateSlug(): string
    {
        $this->loadMissing('purchaseOrder.purchaseRequest.itemCategory');

        return $this->purchaseOrder?->templateSlug() ?? 'consumables';
    }

    public function datesMustBeAfterIarDate(Carbon|string|null $date): bool
    {
        if ($this->iar_date === null || $date === null) {
            return true;
        }

        $value = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $value->greaterThan($this->iar_date);
    }
}
