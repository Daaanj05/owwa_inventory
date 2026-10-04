<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Stock Card export movement window (Approach B: opening balance + in-range rows).
 */
final class StockCardLedgerDateRange
{
    /**
     * @return array{date_from: string, date_to: string}
     */
    public static function fiscalYearDefaults(?CarbonInterface $now = null): array
    {
        $year = ($now ?? now())->year;

        return [
            'date_from' => sprintf('%04d-01-01', $year),
            'date_to' => sprintf('%04d-12-31', $year),
        ];
    }

    /**
     * @return array{from: Carbon, to: Carbon}|null Null means all dates.
     */
    public static function resolve(?string $mode, ?string $dateFrom, ?string $dateTo): ?array
    {
        if ($mode === 'all') {
            return null;
        }

        // Bare URLs with no mode/dates stay "all dates" (backward compatible).
        // Modal always sends date_mode=range with fiscal-year defaults.
        if ($mode !== 'range' && blank($dateFrom) && blank($dateTo)) {
            return null;
        }

        $defaults = self::fiscalYearDefaults();
        $fromRaw = filled($dateFrom) ? $dateFrom : $defaults['date_from'];
        $toRaw = filled($dateTo) ? $dateTo : $defaults['date_to'];

        $from = Carbon::parse($fromRaw)->startOfDay();
        $to = Carbon::parse($toRaw)->endOfDay();

        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @return array{from: Carbon, to: Carbon}|null
     */
    public static function fromRequest(Request $request): ?array
    {
        return self::resolve(
            $request->query('date_mode'),
            $request->query('date_from'),
            $request->query('date_to'),
        );
    }

    /**
     * Query params for export URLs.
     *
     * @return array{date_mode?: string, date_from?: string, date_to?: string}
     */
    public static function toQueryParams(?string $mode, ?string $dateFrom, ?string $dateTo): array
    {
        $mode = filled($mode) ? $mode : 'range';

        if ($mode === 'all') {
            return ['date_mode' => 'all'];
        }

        $resolved = self::resolve('range', $dateFrom, $dateTo);
        if ($resolved === null) {
            return ['date_mode' => 'all'];
        }

        return [
            'date_mode' => 'range',
            'date_from' => $resolved['from']->toDateString(),
            'date_to' => $resolved['to']->toDateString(),
        ];
    }

    /**
     * Keep movements in [from, to]. Prepend Balance forwarded only when
     * movements before the start date leave a quantity greater than zero.
     * Balances on kept rows stay as computed from full history.
     *
     * @param  array<int, array<string, mixed>>  $transactions
     * @return array<int, array<string, mixed>>
     */
    public static function applyApproachB(
        array $transactions,
        CarbonInterface $from,
        CarbonInterface $to,
        bool $newestFirst = false,
    ): array {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $chronological = $transactions;
        usort(
            $chronological,
            fn (array $a, array $b): int => self::txnDateString($a) <=> self::txnDateString($b),
        );

        $before = [];
        $inRange = [];
        foreach ($chronological as $txn) {
            $date = self::txnDateString($txn);
            if ($date === '') {
                continue;
            }
            if ($date < $fromDate) {
                $before[] = $txn;
            } elseif ($date <= $toDate) {
                $inRange[] = $txn;
            }
        }

        $openingBalance = $before === []
            ? 0
            : max(0, (int) ($before[array_key_last($before)]['balance'] ?? 0));

        $sliced = $inRange;
        if ($openingBalance > 0) {
            $officeId = (int) (($inRange[0]['office_id'] ?? null) ?? ($before[0]['office_id'] ?? 0));
            $sliced = [[
                'office_id' => $officeId,
                'sort_date' => $from->copy()->startOfDay(),
                'date' => $fromDate,
                'reference' => 'Balance forwarded',
                'type' => 'opening',
                'receipt_qty' => null,
                'issue_qty' => null,
                'issue_office' => null,
                'office_officer' => null,
                'remarks' => null,
                'property_number' => null,
                'unit_cost' => null,
                'balance' => $openingBalance,
            ], ...$inRange];
        }

        if ($newestFirst) {
            return array_reverse($sliced);
        }

        return $sliced;
    }

    /**
     * @param  array<string, mixed>  $txn
     */
    protected static function txnDateString(array $txn): string
    {
        $raw = $txn['date'] ?? $txn['sort_date'] ?? null;
        if ($raw instanceof CarbonInterface) {
            return $raw->toDateString();
        }

        if (! filled($raw)) {
            return '';
        }

        try {
            return Carbon::parse((string) $raw)->toDateString();
        } catch (\Throwable) {
            return '';
        }
    }
}
