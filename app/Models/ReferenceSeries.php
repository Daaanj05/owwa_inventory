<?php

namespace App\Models;

use App\Models\Concerns\LogsUserActivity;
use Illuminate\Database\Eloquent\Model;

class ReferenceSeries extends Model
{
    use LogsUserActivity;

    public const RESET_NONE = 'none';

    public const RESET_DAILY = 'daily';

    public const RESET_MONTHLY = 'monthly';

    public const RESET_YEARLY = 'yearly';

    public const TYPE_ACQUISITION = 'acquisition';

    public const TYPE_ISSUANCE = 'issuance';

    public const TYPE_ISSUANCE_CONSUMABLES = 'issuance_consumables';

    public const TYPE_ISSUANCE_SEMI = 'issuance_semi';

    public const TYPE_ISSUANCE_PPE = 'issuance_ppe';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_DISPOSAL = 'disposal';

    public const TYPE_DISPOSAL_CONSUMABLES = 'disposal_consumables';

    public const TYPE_DISPOSAL_PROPERTY = 'disposal_property';

    public const TYPE_REQUISITION = 'requisition';

    public const TYPE_EMPLOYEE_REQUISITION_TRANSACTION = 'employee_requisition_transaction';

    public const TYPE_ITEM_CODE_CONSUMABLES = 'item_code_consumables';

    public const TYPE_ITEM_CODE_PPE = 'item_code_ppe';

    public const TYPE_ITEM_CODE_SEMI = 'item_code_semi';

    public const TYPE_PROPERTY_NUMBER_PPE = 'property_number_ppe';

    public const TYPE_PROPERTY_NUMBER_SEMI = 'property_number_semi';

    public const TYPE_PROPERTY_ACTION_REQUEST = 'property_action_request';

    public const TYPE_ACQUISITION_PAPERWORK = 'acquisition_paperwork';

    public const TYPE_PROCUREMENT_PR = 'procurement_pr';

    public const TYPE_PROCUREMENT_PO = 'procurement_po';

    public const TYPE_PROCUREMENT_IAR = 'procurement_iar';

    /** @deprecated Use TYPE_ACQUISITION_PAPERWORK_* constants */
    public const TYPE_ACQUISITION_PAPERWORK_PR = 'acquisition_paperwork_pr';

    public const TYPE_ACQUISITION_PAPERWORK_PO = 'acquisition_paperwork_po';

    public const TYPE_ACQUISITION_PAPERWORK_IAR = 'acquisition_paperwork_iar';

    protected $fillable = [
        'type', 'name', 'prefix', 'pattern', 'next_sequence',
        'reset_period', 'last_generated_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'next_sequence' => 'integer',
            'last_generated_at' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    public static function typeForIssuance(): string
    {
        return self::TYPE_ISSUANCE;
    }

    public static function typeForIssuanceBatch(string $categorySlug): string
    {
        return match ($categorySlug) {
            'consumables' => self::TYPE_ISSUANCE_CONSUMABLES,
            'semi_expendable' => self::TYPE_ISSUANCE_SEMI,
            'ppe' => self::TYPE_ISSUANCE_PPE,
            default => throw new \InvalidArgumentException("Unknown issuance batch category slug: {$categorySlug}"),
        };
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

    public static function typeForDisposalBatch(string $categorySlug): string
    {
        return $categorySlug === 'consumables'
            ? self::TYPE_DISPOSAL_CONSUMABLES
            : self::TYPE_DISPOSAL_PROPERTY;
    }

    public static function typeForRequisition(): string
    {
        return self::TYPE_REQUISITION;
    }

    public static function typeForEmployeeRequisitionTransaction(): string
    {
        return self::TYPE_EMPLOYEE_REQUISITION_TRANSACTION;
    }

    public static function typeForPropertyActionRequest(): string
    {
        return self::TYPE_PROPERTY_ACTION_REQUEST;
    }

    public static function typeForAcquisitionPaperwork(): string
    {
        return self::TYPE_ACQUISITION_PAPERWORK;
    }

    public static function typeForProcurementPr(): string
    {
        return self::TYPE_ACQUISITION_PAPERWORK_PR;
    }

    public static function typeForProcurementPo(): string
    {
        return self::TYPE_ACQUISITION_PAPERWORK_PO;
    }

    public static function typeForProcurementIar(): string
    {
        return self::TYPE_ACQUISITION_PAPERWORK_IAR;
    }

    public static function typeForAcquisitionPaperworkPr(): string
    {
        return self::TYPE_ACQUISITION_PAPERWORK_PR;
    }

    public static function typeForAcquisitionPaperworkPo(): string
    {
        return self::TYPE_ACQUISITION_PAPERWORK_PO;
    }

    public static function typeForAcquisitionPaperworkIar(): string
    {
        return self::TYPE_ACQUISITION_PAPERWORK_IAR;
    }

    /**
     * Series types that output YYYY-MM-#### control numbers (prefix is cosmetic only).
     *
     * @return list<string>
     */
    public static function transactionSeriesTypes(): array
    {
        return [
            self::TYPE_REQUISITION,
            self::TYPE_EMPLOYEE_REQUISITION_TRANSACTION,
            self::TYPE_PROPERTY_ACTION_REQUEST,
            self::TYPE_ISSUANCE,
            self::TYPE_ISSUANCE_CONSUMABLES,
            self::TYPE_ISSUANCE_SEMI,
            self::TYPE_ISSUANCE_PPE,
            self::TYPE_TRANSFER,
            self::TYPE_DISPOSAL,
            self::TYPE_DISPOSAL_CONSUMABLES,
            self::TYPE_DISPOSAL_PROPERTY,
            self::TYPE_ACQUISITION,
            self::TYPE_ACQUISITION_PAPERWORK_PR,
            self::TYPE_ACQUISITION_PAPERWORK_PO,
            self::TYPE_ACQUISITION_PAPERWORK_IAR,
            self::TYPE_PROCUREMENT_PR,
            self::TYPE_PROCUREMENT_PO,
            self::TYPE_PROCUREMENT_IAR,
        ];
    }

    public function isTransactionSeries(): bool
    {
        return in_array($this->type, self::transactionSeriesTypes(), true);
    }

    /**
     * Plain-language description of the pattern for non-technical users.
     */
    public function getPatternDescriptionAttribute(): string
    {
        $p = $this->pattern;
        if (preg_match('/^\{Y\}-\d{2}-\{seq:4\}$/', $p) === 1 || (str_contains($p, '{Y}') && str_contains($p, '{seq:4}') && ! str_contains($p, '{prefix}'))) {
            return 'OWWA control no. YYYY-MM-####';
        }
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
