<?php

namespace App\Support;

use App\Models\Issuance;
use App\Models\Item;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class SemiExpendableUsefulLife
{
    public const STATUS_OK = 'ok';

    public const STATUS_NEARING = 'nearing';

    public const STATUS_EXPIRED = 'expired';

    public static function minYears(): float
    {
        return (float) config('inventory.semi_min_useful_life_years', 1);
    }

    public static function defaultForPropertyClass(?string $propertyClass): ?string
    {
        $class = filled($propertyClass) && array_key_exists($propertyClass, ItemPropertyClass::options())
            ? $propertyClass
            : ItemPropertyClass::OfficeEquipment;

        $default = config("inventory.semi_useful_life_defaults.{$class}");

        return is_string($default) && $default !== '' ? $default : null;
    }

    public static function resolveForItem(?Item $item): ?string
    {
        if ($item === null) {
            return null;
        }

        if (filled($item->estimated_useful_life)) {
            return $item->estimated_useful_life;
        }

        return self::defaultForPropertyClass($item->property_class);
    }

    public static function parseToYears(?string $value): ?float
    {
        if (blank($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if (preg_match('/^([\d.]+)\s*(?:years?|yrs?|y)\b/u', $normalized, $matches) === 1) {
            return (float) $matches[1];
        }

        if (preg_match('/^([\d.]+)\s*(?:months?|mos?|m)\b/u', $normalized, $matches) === 1) {
            return ((float) $matches[1]) / 12;
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    public static function assertEligibleForSemi(?string $value): void
    {
        if (blank($value)) {
            throw ValidationException::withMessages([
                'estimated_useful_life' => 'Estimated useful life is required for semi-expendable issuances (ICS column H).',
            ]);
        }

        $years = self::parseToYears($value);

        if ($years === null) {
            throw ValidationException::withMessages([
                'estimated_useful_life' => 'Enter useful life in years or months (e.g. 5 yrs, 36 months).',
            ]);
        }

        if ($years <= self::minYears()) {
            throw ValidationException::withMessages([
                'estimated_useful_life' => sprintf(
                    'Semi-expendable property must have useful life greater than %s year(s) per COA Circular 2022-004.',
                    rtrim(rtrim(number_format(self::minYears(), 2, '.', ''), '0'), '.'),
                ),
            ]);
        }
    }

    public static function labelSummary(): string
    {
        return 'Agency-determined per COA Circular 2022-004. Guide: machinery & equipment 5–15 years; furniture & fixtures 2–15 years. Must exceed 1 year for semi-expendable eligibility.';
    }

    public static function computeExpiresAt(?CarbonInterface $issuanceDate, ?string $eul): ?Carbon
    {
        if ($issuanceDate === null || blank($eul)) {
            return null;
        }

        $years = self::parseToYears($eul);

        if ($years === null) {
            return null;
        }

        return Carbon::parse($issuanceDate)->addDays((int) round($years * 365.25));
    }

    public static function syncExpiresAt(Issuance $issuance): void
    {
        if ($issuance->item?->category?->getTemplateSlug() !== 'semi_expendable') {
            $issuance->eul_expires_at = null;

            return;
        }

        $issuance->eul_expires_at = self::computeExpiresAt(
            $issuance->issuance_date,
            $issuance->estimated_useful_life,
        );
    }

    public static function daysUntilExpiry(Issuance $issuance): ?int
    {
        if ($issuance->eul_expires_at === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($issuance->eul_expires_at->startOfDay(), false);
    }

    public static function statusForIssuance(Issuance $issuance): ?string
    {
        if ($issuance->item?->category?->getTemplateSlug() !== 'semi_expendable') {
            return null;
        }

        $days = self::daysUntilExpiry($issuance);

        if ($days === null) {
            return null;
        }

        if ($days < 0) {
            return self::STATUS_EXPIRED;
        }

        $nearingDays = (int) config('inventory.eul_nearing_days', 90);

        if ($days <= $nearingDays) {
            return self::STATUS_NEARING;
        }

        return self::STATUS_OK;
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            self::STATUS_EXPIRED => 'Expired',
            self::STATUS_NEARING => 'Nearing expiry',
            self::STATUS_OK => 'Active',
            default => '—',
        };
    }
}
