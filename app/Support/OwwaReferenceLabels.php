<?php

namespace App\Support;

use App\Models\Disposal;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PhysicalCountSession;
use App\Models\Transfer;

class OwwaReferenceLabels
{
    public const RIS = 'RIS No.';

    public const SERIAL = 'Serial No.';

    public const PAR = 'PAR No.';

    public const ICS = 'ICS No.';

    public const PTR = 'PTR No.';

    public const STOCK_CARD_REFERENCE = 'Reference';

    public const RLSDDP = 'RLSDDP No.';

    public const WMR = 'WMR No.';

    public const IIRUP = 'IIRUP No.';

    public const STOCK_NO = 'Stock No.';

    public const PROPERTY_NO = 'Property No.';

    public const INVENTORY_ITEM_NO = 'Inventory item no.';

    public static function requisition(): string
    {
        return self::RIS;
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
            'ppe', 'semi_expendable' => self::IIRUP,
            default => self::IIRUP,
        };
    }

    public static function incidentReport(): string
    {
        return self::RLSDDP;
    }

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
        $slug = $transfer->item?->category?->getTemplateSlug();

        return self::assetIdentifierValue($slug, $transfer->property_number, $transfer->item?->item_code);
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
            'ppe' => 'Assigned automatically on issuance from the PAR property number series.',
            'semi_expendable' => 'Assigned automatically on issuance: SPLV/SPHV-Year-SupplyType-UACS-DeptCode-Seq (e.g. SPLV-2024-ICT-106-01-001). Value tier: '.SemiExpendableValueCategory::tierRuleSummary().' (COA Circular 2022-004).',
            default => '',
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
