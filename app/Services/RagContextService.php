<?php

namespace App\Services;

use App\Models\Issuance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds context payload for RAG (Retrieval-Augmented Generation).
 * Returns structured data from the inventory system so an LLM (e.g. Ollama/DeepSeek)
 * can answer questions using real numbers. Supports flexible date range (e.g. up to 5 years).
 */
class RagContextService
{
    public function __construct(
        protected DataCoverageService $dataCoverage,
        protected ConsumptionAnalyticsService $consumptionAnalytics,
        protected InventoryStockService $inventoryStock
    ) {}

    /**
     * Build full RAG context for the given date range.
     * If maxMonths is set (e.g. 60 for 5 years), only that much history is included.
     *
     * @return array{data_range: array, summary: array, consumption_by_department: array, low_stock_summary: array, generated_at: string}
     */
    public function buildContext(?Carbon $from = null, ?Carbon $to = null, ?int $maxMonths = 60): array
    {
        $range = $this->dataCoverage->getDataRange();

        if ($range['from'] === null || $range['to'] === null) {
            return $this->emptyContext($range['label']);
        }

        $from = $from ?? $range['from'];
        $to = $to ?? $range['to'];

        if ($maxMonths !== null) {
            $limitFrom = $to->copy()->subMonths($maxMonths);
            if ($from->lt($limitFrom)) {
                $from = $limitFrom;
            }
        }

        $consumption = $this->consumptionAnalytics->getConsumptionTotalsByDepartment(
            $from,
            $to,
            [],
            []
        );

        $consumptionSummary = $this->consumptionAnalytics->getConsumptionSummary($from, $to, [], []);

        $lowStockCount = $this->inventoryStock->lowStockCount();

        $months = $from->diffInMonths($to) + 1;
        $years = round($months / 12, 1);

        return [
            'data_range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'months' => $months,
                'years' => $years,
                'label' => $years >= 1 ? ($years . ' years') : ($months . ' months'),
            ],
            'summary' => [
                'total_consumption_units' => $consumptionSummary['total'],
                'top_department' => $consumptionSummary['top_department_name'],
                'top_department_quantity' => $consumptionSummary['top_department_quantity'],
                'avg_consumption_per_period' => $consumptionSummary['avg_per_period'],
            ],
            'consumption_by_department' => array_map(
                fn ($label, $value) => ['department' => $label, 'quantity' => $value],
                array_values($consumption['labels']),
                $consumption['values']
            ),
            'low_stock_summary' => [
                'item_office_pairs_at_or_below_reorder' => $lowStockCount,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function emptyContext(string $label): array
    {
        return [
            'data_range' => [
                'from' => null,
                'to' => null,
                'months' => 0,
                'years' => 0.0,
                'label' => $label,
            ],
            'summary' => [
                'total_consumption_units' => 0,
                'top_department' => null,
                'top_department_quantity' => 0,
                'avg_consumption_per_period' => 0,
            ],
            'consumption_by_department' => [],
            'low_stock_summary' => [
                'item_office_pairs_at_or_below_reorder' => 0,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
