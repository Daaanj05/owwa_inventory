<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhysicalCountSession extends Model
{
    use HasFactory, LogsUserActivity;

    public const TYPE_RPCI = 'rpci';

    public const TYPE_RPCPPE = 'rpcppe';

    public const TYPE_RPCSP = 'rpcsp';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_COMPLETE = 'complete';

    protected $fillable = [
        'reference_code',
        'count_type',
        'status',
        'book_list_loaded',
        'office_id',
        'item_category_id',
        'count_date',
        'inventory_type_label',
        'property_class',
        'fund_cluster',
        'accountable_officer_name',
        'accountable_officer_designation',
        'date_of_assumption',
        'certified_by_printed_name',
        'approved_by_printed_name',
        'verified_by_printed_name',
        'recorded_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'count_date' => 'date',
            'date_of_assumption' => 'date',
            'completed_at' => 'datetime',
            'book_list_loaded' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PhysicalCountSession $session): void {
            if (blank($session->status)) {
                $session->status = self::STATUS_IN_PROGRESS;
            }

            if (blank($session->reference_code)) {
                $session->reference_code = 'PC-'.now()->format('Ymd').'-'.str_pad(
                    (string) (static::query()->whereDate('created_at', now()->toDateString())->count() + 1),
                    4,
                    '0',
                    STR_PAD_LEFT
                );
            }

            if (blank($session->recorded_by) && auth()->id()) {
                $session->recorded_by = auth()->id();
            }
        });
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
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
        return $this->hasMany(PhysicalCountLine::class);
    }

    public function scanEvents(): HasMany
    {
        return $this->hasMany(PhysicalCountScanEvent::class);
    }

    public function supportsQrScanning(): bool
    {
        return in_array($this->count_type, [self::TYPE_RPCPPE, self::TYPE_RPCSP], true);
    }

    public function expectedUnits(): int
    {
        return (int) $this->lines()->sum('balance_per_card');
    }

    public function scannedUnits(): int
    {
        return (int) $this->lines()->sum('on_hand_count');
    }

    public function hasBookListLoaded(): bool
    {
        return (bool) $this->book_list_loaded;
    }

    /**
     * @return array{expected: int, scanned: int, shortages: int, overages: int, matched: int, scan_only: bool}
     */
    public function countSummary(): array
    {
        $this->loadMissing('lines');

        if ($this->supportsQrScanning() && ! $this->hasBookListLoaded()) {
            $scanned = (int) $this->lines->sum('on_hand_count');

            return [
                'expected' => $scanned,
                'scanned' => $scanned,
                'shortages' => 0,
                'overages' => 0,
                'matched' => $this->lines->count(),
                'scan_only' => true,
            ];
        }

        $shortages = 0;
        $overages = 0;
        $matched = 0;

        foreach ($this->lines as $line) {
            $variance = $line->shortageOverageQuantity();
            if ($variance < 0) {
                $shortages++;
            } elseif ($variance > 0) {
                $overages++;
            } else {
                $matched++;
            }
        }

        return [
            'expected' => (int) $this->lines->sum('balance_per_card'),
            'scanned' => (int) $this->lines->sum('on_hand_count'),
            'shortages' => $shortages,
            'overages' => $overages,
            'matched' => $matched,
            'scan_only' => false,
        ];
    }

    public function templateSlug(): string
    {
        return match ($this->count_type) {
            self::TYPE_RPCPPE => 'ppe',
            self::TYPE_RPCSP => 'semi_expendable',
            default => 'consumables',
        };
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isIncomplete(): bool
    {
        return $this->status === self::STATUS_INCOMPLETE;
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    /**
     * @return array<int, string>
     */
    public function missingCompletionFields(): array
    {
        $missing = [];

        foreach (['fund_cluster', 'accountable_officer_name', 'inventory_type_label', 'count_date'] as $field) {
            if (blank($this->{$field})) {
                $missing[] = str_replace('_', ' ', $field);
            }
        }

        foreach (['certified_by_printed_name', 'approved_by_printed_name', 'verified_by_printed_name'] as $field) {
            if (blank($this->{$field})) {
                $missing[] = str_replace('_', ' ', $field);
            }
        }

        return $missing;
    }

    public function tallyLabel(): string
    {
        $summary = $this->countSummary();
        $expected = $summary['expected'];

        if ($expected === 0) {
            return $this->hasBookListLoaded() ? '—' : '0 scanned';
        }

        $label = "{$summary['scanned']}/{$expected}";

        if ($summary['shortages'] > 0) {
            return "{$label} ({$summary['shortages']} short)";
        }

        return $label;
    }
}
