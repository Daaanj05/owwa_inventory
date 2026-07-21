<?php

namespace App\Support;

use App\Models\Disposal;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PhysicalCountSession;
use App\Models\Transfer;

class OwwaReferenceLabels
{
    public const RIS = 'RIS No.';

    public const RSMI = 'RSMI No.';

    /** RSMI Appendix 64 header label — maps to issuance {@see Issuance::$reference_code}, not item serial metadata. */
    public const SERIAL = 'Serial No.';

    public const PAR = 'PAR No.';

    public const ICS = 'ICS No.';

    public const PTR = 'PTR No.';

    public const STOCK_CARD_REFERENCE = 'Reference';

    public const RLSDDP = 'RLSDDP No.';

    public const WMR = 'WMR No.';

    public const IIRUP = 'IIRUP No.';

    public const IIRUSP = 'IIRUSP No.';

    public const STOCK_NO = 'Stock No.';

    public const PROPERTY_NO = 'Property No.';

    public const INVENTORY_ITEM_NO = 'Inventory item no.';

    public const TRANSACTION_NO = 'Transaction No.';

    public static function requisition(): string
    {
        return self::RIS;
    }

    public static function employeeRequisitionTransaction(): string
    {
        return self::TRANSACTION_NO;
    }

    public static function assetIdentifierTableHeader(): string
    {
        return 'Asset No.';
    }

    public static function acquisition(): string
    {
        return self::STOCK_CARD_REFERENCE;
    }

    public static function transfer(?string $categorySlug = null): string
    {
        return self::PTR;
    }

    public static function disposal(?string $categorySlug = null): string
    {
        $slug = $categorySlug ?? self::activeCategorySlug();

        return match ($slug) {
            'consumables' => self::WMR,
            'ppe' => self::IIRUP,
            'semi_expendable' => self::IIRUSP,
            default => self::IIRUP,
        };
    }

    public static function incidentReport(): string
    {
        return self::RLSDDP;
    }

    /**
     * OWWA control-number label for issuance exports and UI.
     *
     * Consumables: RSMI "Serial No." ({@see Issuance::$reference_code}, YYYY-MM-####).
     * PPE: PAR No. Semi-expendable: ICS No.
     */
    public static function issuanceControl(?string $categorySlug = null): string
    {
        $slug = $categorySlug ?? self::activeCategorySlug();

        return match ($slug) {
            'ppe' => self::PAR,
            'semi_expendable' => self::ICS,
            default => self::SERIAL,
        };
    }

    public static function forIssuance(Issuance $issuance): string
    {
        $issuance->loadMissing('item.category');

        return self::issuanceControl($issuance->item?->category?->getTemplateSlug());
    }

    public static function issuanceTransaction(Issuance $issuance): string
    {
        $issuance->loadMissing('item.category');

        return match ($issuance->item?->category?->getTemplateSlug()) {
            'ppe' => self::PAR,
            'semi_expendable' => self::ICS,
            default => self::RSMI,
        };
    }

    public static function usesPropertyNumber(?string $categorySlug): bool
    {
        return in_array($categorySlug, ['ppe', 'semi_expendable'], true);
    }

    public static function assetIdentifierLabel(?string $categorySlug): string
    {
        return match ($categorySlug) {
            'ppe' => self::PROPERTY_NO,
            'semi_expendable' => self::INVENTORY_ITEM_NO,
            default => self::STOCK_NO,
        };
    }

    public static function assetIdentifierHeaderLabel(?string $categorySlug): string
    {
        return self::assetIdentifierLabel($categorySlug);
    }

    public static function assetIdentifierValue(?string $categorySlug, ?string $propertyNumber, ?string $itemCode): ?string
    {
        if (self::usesPropertyNumber($categorySlug)) {
            return filled($propertyNumber) ? $propertyNumber : null;
        }

        return filled($itemCode) ? $itemCode : null;
    }

    public static function assetIdentifierForIssuance(Issuance $issuance): ?string
    {
        $issuance->loadMissing('item.category');
        $slug = $issuance->item?->category?->getTemplateSlug();

        return self::assetIdentifierValue($slug, $issuance->property_number, $issuance->item?->item_code);
    }

    public static function assetIdentifierForTransfer(Transfer $transfer): ?string
    {
        $transfer->loadMissing('item.category');
        $item = $transfer->item;
        $slug = $item?->category?->getTemplateSlug();

        if (! self::usesPropertyNumber($slug)) {
            return filled($item?->item_code) ? (string) $item->item_code : null;
        }

        $resolved = self::resolvedCatalogPropertyNumber($transfer);
        $stored = filled($transfer->property_number) ? (string) $transfer->property_number : null;
        $itemCode = filled($item?->item_code) ? (string) $item->item_code : null;

        // Prefer a real inventory/property number over a mistaken stock/item code.
        if ($stored !== null && ($itemCode === null || $stored !== $itemCode)) {
            return $stored;
        }

        if (filled($resolved) && ($itemCode === null || $resolved !== $itemCode)) {
            return $resolved;
        }

        if ($stored !== null) {
            return $stored;
        }

        return filled($resolved) ? $resolved : null;
    }

    /**
     * Semi = inventory item no.; PPE = property no. Never stock/item codes.
     */
    public static function resolvedCatalogPropertyNumber(Transfer $transfer): ?string
    {
        $transfer->loadMissing('item.category');
        $item = $transfer->item;
        if ($item === null) {
            return null;
        }

        $slug = $item->category?->getTemplateSlug();

        if ($slug === 'semi_expendable') {
            $number = $item->resolvedSemiExpendablePropertyNumber(
                $transfer->unit_cost !== null ? (float) $transfer->unit_cost : null,
            );

            return filled($number) ? (string) $number : null;
        }

        if ($slug === 'ppe') {
            $number = $item->resolvedPpePropertyNumber();
            if (filled($number)) {
                return (string) $number;
            }

            $fromUnit = InventoryUnit::query()
                ->where('item_id', $item->id)
                ->whereNotNull('property_number')
                ->orderByDesc('id')
                ->value('property_number');

            return filled($fromUnit) ? (string) $fromUnit : null;
        }

        return null;
    }

    public static function assetIdentifierForDisposal(Disposal $disposal): ?string
    {
        $disposal->loadMissing('item.category');
        $slug = $disposal->item?->category?->getTemplateSlug();

        return self::assetIdentifierValue($slug, $disposal->property_number, $disposal->item?->item_code);
    }

    public static function stockNumberHelperText(): string
    {
        return 'Assigned automatically when the item is registered under Inventory → category → Items (Reference series: item code per category).';
    }

    public static function propertyNumberHelperText(?string $categorySlug): string
    {
        return match ($categorySlug) {
            'ppe' => 'Assigned at item register: YEAR-CLASS-UACS-SEQ-LOCATION (e.g. 2026-IT-106-001-RWO4A). One Property No. per catalog item; acquisition units reuse it.',
            'semi_expendable' => 'Assigned at item register as TEMP-YEAR-CLASS-UACS-SEQ-LOCATION, then finalized to SPLV/SPHV on first acquisition unit cost (≤₱5,000 SPLV; above SPHV). One Inventory item no. per catalog item.',
            default => '',
        };
    }

    public static function propertyIdentifierLedgerTooltip(?string $categorySlug): string
    {
        return match ($categorySlug) {
            'ppe' => 'Property number assigned at acquisition; shown from issuance custody.',
            'semi_expendable' => 'Inventory item number assigned at regional acquisition (one per item catalog).',
            default => 'Property or inventory identifier for this item.',
        };
    }

    public static function propertyIssuanceDateLedgerTooltip(?string $categorySlug): string
    {
        return match ($categorySlug) {
            'ppe' => 'Date this property was issued to you on a PAR.',
            'semi_expendable' => 'Date this property was issued to you on an ICS.',
            default => 'Date this property was issued to you.',
        };
    }

    public static function itemCategorySlug(?int $itemId): ?string
    {
        if (blank($itemId)) {
            return null;
        }

        return Item::query()->with('category')->find($itemId)?->category?->getTemplateSlug();
    }

    public static function physicalCount(?string $countType = null): string
    {
        return match ($countType) {
            PhysicalCountSession::TYPE_RPCI => 'RPCI reference',
            PhysicalCountSession::TYPE_RPCPPE => 'RPCPPE reference',
            PhysicalCountSession::TYPE_RPCSP => 'RPCSP reference',
            default => 'Reference',
        };
    }

    public static function acquisitionPaperwork(): string
    {
        return 'Acquisition paperwork reference';
    }

    /** @deprecated Use acquisitionPaperwork() */
    public static function procurementCase(): string
    {
        return self::acquisitionPaperwork();
    }

    public static function activeCategorySlug(): ?string
    {
        $categoryId = session('active_item_category_id');
        if (! filled($categoryId)) {
            return null;
        }

        return ItemCategory::query()->find($categoryId)?->getTemplateSlug();
    }
}
