<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyActionRequest extends Model
{
    use LogsUserActivity;

    public const ACTION_RETURN = 'return';

    public const ACTION_REPLACEMENT = 'replacement';

    public const ACTION_DISPOSAL = 'disposal';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_UC = 'pending_uc';

    public const STATUS_PENDING_SC = 'pending_sc';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXECUTED = 'executed';

    protected $fillable = [
        'reference_code',
        'action_type',
        'reason_code',
        'reason_detail',
        'requested_by',
        'accountable_user_id',
        'office_id',
        'department_id',
        'status',
        'uc_approved_by',
        'uc_approved_at',
        'uc_remarks',
        'sc_approved_by',
        'sc_approved_at',
        'sc_remarks',
        'offline_approval_received',
        'offline_approval_date',
        'offline_approval_attachment',
        'offline_signatories',
        'replacement_requisition_id',
        'executed_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'uc_approved_at' => 'datetime',
            'sc_approved_at' => 'datetime',
            'offline_approval_received' => 'boolean',
            'offline_approval_date' => 'date',
            'executed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PropertyActionRequestLine::class)->orderBy('sort_order');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function accountableUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accountable_user_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function ucApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uc_approved_by');
    }

    public function scApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sc_approved_by');
    }

    public function replacementRequisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class, 'replacement_requisition_id');
    }

    public function propertyCount(): int
    {
        return $this->lines()->count();
    }

    public function propertyNumbersLabel(): string
    {
        $this->loadMissing('lines.issuance');

        $numbers = $this->lines
            ->map(fn (PropertyActionRequestLine $line): ?string => $line->issuance?->property_number
                ?? $line->issuance?->reference_code)
            ->filter()
            ->values();

        if ($numbers->isEmpty()) {
            return '—';
        }

        if ($numbers->count() === 1) {
            return (string) $numbers->first();
        }

        return $numbers->first().' +'.($numbers->count() - 1).' more';
    }

    public function reasonLabel(): string
    {
        $reasons = config('property_action_reasons.'.$this->action_type, []);

        return $reasons[$this->reason_code] ?? $this->reason_code;
    }

    public function actionTypeLabel(): string
    {
        return match ($this->action_type) {
            self::ACTION_RETURN => 'Return',
            self::ACTION_REPLACEMENT => 'Replacement',
            self::ACTION_DISPOSAL => 'Disposal',
            default => ucfirst((string) $this->action_type),
        };
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
        return $this->isEmployeeRequest()
            && $this->isDraft()
            && ! $this->isArchived();
    }

    public function canEmployeeArchive(): bool
    {
        return $this->canEmployeeEdit();
    }

    public function canEmployeeSubmit(): bool
    {
        if (! $this->canEmployeeEdit()) {
            return false;
        }

        $this->loadMissing('lines');

        return $this->lines->isNotEmpty();
    }

    public function canEmployeeRestore(): bool
    {
        return $this->isEmployeeRequest()
            && $this->isArchived()
            && $this->isDraft();
    }

    public function isEmployeeRequest(): bool
    {
        $this->loadMissing('requestedBy');

        return $this->requestedBy?->role === User::ROLE_EMPLOYEE;
    }

    public function isUcRequest(): bool
    {
        $this->loadMissing('requestedBy');

        return $this->requestedBy?->role === User::ROLE_UNIT_CONSOLIDATOR;
    }

    public function canUcEdit(): bool
    {
        return $this->isUcRequest()
            && $this->isDraft()
            && ! $this->isArchived();
    }

    public function canUcSendToSc(): bool
    {
        if (! $this->canUcEdit()) {
            return false;
        }

        $this->loadMissing('lines');

        return $this->lines->isNotEmpty();
    }

    public function canUcArchive(): bool
    {
        return $this->canUcEdit();
    }

    public function canUcRestore(): bool
    {
        return $this->isUcRequest()
            && $this->isArchived()
            && $this->isDraft();
    }

    public function statusLabel(): string
    {
        return str_replace('_', ' ', ucwords((string) $this->status, '_'));
    }
}
