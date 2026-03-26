<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferenceSeries extends Model
{
    public const RESET_NONE = 'none';

    public const RESET_DAILY = 'daily';

    public const RESET_MONTHLY = 'monthly';

    public const RESET_YEARLY = 'yearly';

    public const TYPE_ACQUISITION = 'acquisition';

    public const TYPE_ISSUANCE = 'issuance';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_DISPOSAL = 'disposal';

    public const TYPE_REQUISITION = 'requisition';

    protected $fillable = [
        'type', 'name', 'prefix', 'pattern', 'next_sequence',
        'reset_period', 'last_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'next_sequence' => 'integer',
            'last_generated_at' => 'date',
        ];
    }

    public static function typeForIssuance(): string
    {
        return self::TYPE_ISSUANCE;
    }

    public static function typeForAcquisition(): string
    {
        return self::TYPE_ACQUISITION;
    }

    public static function typeForTransfer(): string
    {
        return self::TYPE_TRANSFER;
    }

    public static function typeForDisposal(): string
    {
        return self::TYPE_DISPOSAL;
    }

    public static function typeForRequisition(): string
    {
        return self::TYPE_REQUISITION;
    }

    /**
     * Plain-language description of the pattern for non-technical users.
     */
    public function getPatternDescriptionAttribute(): string
    {
        $p = $this->pattern;
        if (str_contains($p, '{prefix}') && str_contains($p, '{Y}') && str_contains($p, '{seq:4}')) {
            return 'Code letters – Year – Number (4 digits)';
        }
        if (str_contains($p, '{prefix}') && str_contains($p, '{Y}') && str_contains($p, '{seq}')) {
            return 'Code letters – Year – Number';
        }
        if (str_contains($p, '{prefix}')) {
            return 'Code letters and sequence';
        }

        return 'Custom format';
    }
}
