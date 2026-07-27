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

    public static function minMonths(): int
    {
        return (int) max(1, (int) round(self::minYears() * 12));
    }

    public static function nearingPercentRemaining(): float
    {
        return (float) config('inventory.eul_nearing_percent_remaining', 20);
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

    public static function parseToMonths(?string $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if (preg_match('/^([\d.]+)\s*(?:months?|mos?|m)\b/u', $normalized, $matches) === 1) {
            return (int) round((float) $matches[1]);
        }

        if (preg_match('/^([\d.]+)\s*(?:years?|yrs?|y)\b/u', $normalized, $matches) === 1) {
            return (int) round(((float) $matches[1]) * 12);
        }

        if (preg_match('/^(\d+)$/', $normalized, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    public static function parseToYears(?string $value): ?float
    {
        $months = self::parseToMonths($value);

        if ($months === null) {
            return null;
        }

        return $months / 12;
    }

    public static function formatFromMonths(int $months): string
    {
        $months = max(0, $months);
        $years = intdiv($months, 12);
        $remainder = $months % 12;

        if ($years > 0 && $remainder === 0) {
            return sprintf(
                '%d %s (%d %s)',
                $months,
                $months === 1 ? 'month' : 'months',
                $years,
                $years === 1 ? 'year' : 'years',
            );
        }

        if ($years > 0) {
            return sprintf(
                '%d months (%d %s %d %s)',
                $months,
                $years,
                $years === 1 ? 'year' : 'years',
                $remainder,
                $remainder === 1 ? 'month' : 'months',
            );
        }

        return sprintf('%d %s', $months, $months === 1 ? 'month' : 'months');
    }

    public static function formatDuration(?string $value): string
    {
        $months = self::parseToMonths($value);

        if ($months === null) {
            return filled($value) ? (string) $value : '—';
        }

        return self::formatFromMonths($months);
    }

    public static function storeFromMonths(int|string|null $months): ?string
    {
        if ($months === null || $months === '') {
            return null;
        }

        $value = (int) $months;

        if ($value <= 0) {
            return null;
        }

        return $value.' months';
    }

    /**
     * @throws ValidationException
     */
    public static function assertEligibleForSemi(?string $value): void
    {
        if (blank($value)) {
            throw ValidationException::withMessages([
                'estimated_useful_life' => 'Estimated useful life (months) is required for semi-expendable issuances (ICS column H).',
            ]);
        }

        $months = self::parseToMonths($value);

        if ($months === null) {
            throw ValidationException::withMessages([
                'estimated_useful_life' => 'Enter useful life in months (e.g. 36). Labels show months and years.',
            ]);
        }

        if ($months <= self::minMonths()) {
            throw ValidationException::withMessages([
                'estimated_useful_life' => sprintf(
                    'Semi-expendable property must have useful life greater than %d month(s) (more than %s year(s)) per COA Circular 2022-004.',
                    self::minMonths(),
                    rtrim(rtrim(number_format(self::minYears(), 2, '.', ''), '0'), '.'),
                ),
            ]);
        }
    }

    public static function labelSummary(): string
    {
        return 'Enter duration in months. Labels show months and years (e.g. 36 months / 3 years). Agency-determined per COA Circular 2022-004. Must exceed 12 months for semi-expendable eligibility.';
    }

    public static function computeExpiresAt(?CarbonInterface $issuanceDate, ?string $eul): ?Carbon
    {
        if ($issuanceDate === null || blank($eul)) {
            return null;
        }

        $months = self::parseToMonths($eul);

        if ($months === null || $months <= 0) {
            return null;
        }

        return Carbon::parse($issuanceDate)->addMonthsNoOverflow($months);
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

    public static function totalLifeDays(Issuance $issuance): ?int
    {
        $months = self::parseToMonths($issuance->estimated_useful_life);

        if ($months === null || $months <= 0 || $issuance->issuance_date === null) {
            return null;
        }

        $start = Carbon::parse($issuance->issuance_date)->startOfDay();
        $end = $start->copy()->addMonthsNoOverflow($months);

        return (int) $start->diffInDays($end);
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

        $totalDays = self::totalLifeDays($issuance);

        if ($totalDays === null || $totalDays <= 0) {
            return self::STATUS_OK;
        }

        $percentRemaining = ($days / $totalDays) * 100;

        if ($percentRemaining <= self::nearingPercentRemaining()) {
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
