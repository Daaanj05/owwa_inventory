<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\Issuance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DataCoverageService
{
    /**
     * Get the range of historical data (earliest and latest across issuances and acquisitions).
     *
     * @return array{from: Carbon|null, to: Carbon|null, months: int, years: float, label: string}
     */
    public function getDataRange(): array
    {
        $fiscal = app(\App\Services\FiscalYearService::class);
        $range = $fiscal->range();

        if ($range !== null) {
            $from = $range['from'];
            $to   = $range['to'];
        } else {
            $issuanceRange = Issuance::query()
                ->selectRaw('MIN(issuance_date) as min_date, MAX(issuance_date) as max_date')
                ->first();

            $acquisitionRange = Acquisition::query()
                ->selectRaw('MIN(acquisition_date) as min_date, MAX(acquisition_date) as max_date')
                ->first();

            $dates = collect();
            if ($issuanceRange && $issuanceRange->min_date) {
                $dates->push(Carbon::parse($issuanceRange->min_date));
                $dates->push(Carbon::parse($issuanceRange->max_date));
            }
            if ($acquisitionRange && $acquisitionRange->min_date) {
                $dates->push(Carbon::parse($acquisitionRange->min_date));
                $dates->push(Carbon::parse($acquisitionRange->max_date));
            }

            if ($dates->isEmpty()) {
                return [
                    'from' => null,
                    'to' => null,
                    'months' => 0,
                    'years' => 0.0,
                    'label' => 'No data yet',
                ];
            }

            $from = $dates->min();
            $to   = $dates->max();
        }

        $months = (int) $from->diffInMonths($to) + 1;
        $years = round($months / 12, 1);

        if ($years >= 1) {
            $label = $years == 1 ? '1 year' : $years . ' years';
        } else {
            $label = $months <= 1 ? '1 month' : $months . ' months';
        }

        return [
            'from' => $from,
            'to' => $to,
            'months' => $months,
            'years' => $years,
            'label' => $label,
        ];
    }
}
