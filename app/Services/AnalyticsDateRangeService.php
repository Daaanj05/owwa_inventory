<?php

namespace App\Services;

use App\Models\Issuance;
use Carbon\Carbon;

class AnalyticsDateRangeService
{
    public const SCOPE_CURRENT_YEAR = 'current_year';

    public const SCOPE_LONG_VIEW = 'long_view';

    /**
     * @return array{from: Carbon, to: Carbon, label: string}
     */
    public function currentYearRange(): array
    {
        $now = now();
        $year = $now->year;

        return [
            'from' => $now->copy()->startOfYear(),
            'to' => $now->copy()->endOfYear(),
            'label' => "Calendar year {$year}",
        ];
    }

    /**
     * @return array{from: Carbon, to: Carbon, label: string}
     */
    public function longViewRange(int $maxMonths = 60): array
    {
        $to = now()->endOfMonth();
        $from = $to->copy()->subMonths($maxMonths - 1)->startOfMonth();

        $latestIssuance = Issuance::query()->max('issuance_date');
        if ($latestIssuance !== null) {
            $to = Carbon::parse($latestIssuance);
        }

        $earliestIssuance = Issuance::query()->min('issuance_date');
        if ($earliestIssuance !== null) {
            $earliest = Carbon::parse($earliestIssuance)->startOfMonth();
            if ($from->lt($earliest)) {
                $from = $earliest;
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'label' => 'Multi-year view',
        ];
    }

    /**
     * @return array{from: Carbon, to: Carbon, label: string}
     */
    public function getRangeForScope(string $scope, int $maxMonths = 60): array
    {
        return match ($scope) {
            self::SCOPE_LONG_VIEW => $this->longViewRange($maxMonths),
            default => $this->currentYearRange(),
        };
    }

    /**
     * @param  array{analytics_scope?: string, date_from?: string, date_to?: string}  $filters
     * @return array{from: Carbon, to: Carbon, includeYearInLabels: bool}
     */
    public function resolveFromWidgetFilters(array $filters): array
    {
        $scope = $filters['analytics_scope'] ?? self::SCOPE_CURRENT_YEAR;
        $includeYearInLabels = $scope === self::SCOPE_LONG_VIEW;

        if (! empty($filters['date_from']) && ! empty($filters['date_to'])) {
            return [
                'from' => Carbon::parse($filters['date_from'])->startOfDay(),
                'to' => Carbon::parse($filters['date_to'])->endOfDay(),
                'includeYearInLabels' => $includeYearInLabels,
            ];
        }

        $range = $this->getRangeForScope($scope);

        return [
            'from' => $range['from'],
            'to' => $range['to'],
            'includeYearInLabels' => $includeYearInLabels,
        ];
    }
}
