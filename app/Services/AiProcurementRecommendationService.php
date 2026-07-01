<?php

namespace App\Services;

use App\Models\AiProcurementItem;
use App\Models\AiProcurementRun;
use App\Models\ItemCategory;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class AiProcurementRecommendationService
{
    private const int AT_RISK_LIMIT = 25;

    public function __construct(
        protected ProcurementDecisionSupportService $decisionSupport,
        protected RagService $rag,
    ) {}

    public function createProcessingRun(Carbon $from, Carbon $to, ?int $createdBy): AiProcurementRun
    {
        return AiProcurementRun::create([
            'ran_at' => now(),
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'status' => 'processing',
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @param  array<int>  $officeIds
     */
    public function processRun(
        int $runId,
        string $periodFrom,
        string $periodTo,
        ?int $categoryId,
        array $officeIds,
    ): void {
        $run = AiProcurementRun::query()->findOrFail($runId);

        if ($run->status !== 'processing') {
            return;
        }

        try {
            $from = Carbon::parse($periodFrom)->startOfDay();
            $to = Carbon::parse($periodTo)->endOfDay();

            $rows = $this->decisionSupport->getAtRiskRows(
                from: $from,
                to: $to,
                categoryId: $categoryId,
                officeIds: $officeIds,
                movingAverageMonths: 6,
                forecastHorizonMonths: 3,
                targetCoverMonths: 3,
                limit: self::AT_RISK_LIMIT,
            );

            $categoryName = $categoryId ? (ItemCategory::find($categoryId)?->name ?? null) : null;
            $high = $rows->where('priority', 'High')->count();
            $medium = $rows->where('priority', 'Medium')->count();
            $pairs = $rows->count();

            $headline = $pairs === 0
                ? 'No at-risk pairs in this filter'
                : sprintf('%d at-risk pairs · %d High · %d Medium', $pairs, $high, $medium);

            $facts = [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'category' => $categoryName,
                'pairs' => $pairs,
                'high' => $high,
                'medium' => $medium,
                'headline' => $headline,
            ];

            $itemFacts = $rows->map(fn ($row) => [
                'priority' => $row->priority,
                'item' => $row->item_name,
                'office' => $row->office_name,
                'cover' => (float) ($row->months_cover ?? 0),
                'forecast' => (float) $row->forecast_monthly_usage,
                'suggested' => $row->suggested_reorder_qty ?? null,
                'has_recent_usage' => (bool) ($row->has_recent_usage ?? true),
            ])->values()->all();

            $summary = $this->rag->generateNarrativeSummary($facts, $itemFacts, [
                'category_id' => $categoryId,
            ]);

            $table = $this->buildDeterministicMarkdownTable($rows);
            $rawForStorage = $summary === null
                ? 'Ollama is not available. Showing deterministic recommendations without AI narrative summary.'."\n\n".$table
                : trim($summary)."\n\n".$table;

            $this->finalizeRun($run, $rawForStorage, $rows);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error_message' => $this->formatErrorMessage($exception->getMessage()),
            ]);
        }
    }

    public function markRunFailed(int $runId, string $message): void
    {
        AiProcurementRun::query()
            ->whereKey($runId)
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'error_message' => $this->formatErrorMessage($message),
            ]);
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    public function finalizeRun(AiProcurementRun $run, string $rawResponse, Collection $rows): void
    {
        $clean = preg_replace('/<think>.*?<\/think>/s', '', $rawResponse);
        $clean = str_replace(["\r\n", "\r"], "\n", trim((string) $clean));

        if ($clean === '') {
            $run->update([
                'status' => 'failed',
                'error_message' => 'No recommendation content was generated.',
            ]);

            return;
        }

        $run->update([
            'summary' => $this->extractSummaryLine($clean),
            'raw_response' => $clean,
            'status' => 'draft',
            'error_message' => null,
        ]);

        $run->items()->delete();

        foreach ($rows as $row) {
            $reason = sprintf(
                'Stock %d (reorder %d), forecast %.1f/mo, cover %.1f months.',
                (int) $row->current_stock,
                (int) $row->reorder_level,
                (float) $row->forecast_monthly_usage,
                (float) ($row->months_cover ?? 0)
            );

            $suggested = $row->suggested_reorder_qty ?? null;

            AiProcurementItem::create([
                'run_id' => $run->id,
                'section' => 'urgent',
                'priority' => $row->priority,
                'item_name' => $row->item_name,
                'item_id' => $row->item_id,
                'office_name' => $row->office_name,
                'office_id' => $row->office_id,
                'current_stock' => (int) $row->current_stock,
                'avg_monthly_usage' => (float) $row->forecast_monthly_usage,
                'months_cover' => (float) ($row->months_cover ?? 0),
                'suggested_qty_min' => $suggested,
                'suggested_qty_max' => $suggested,
                'reason' => $reason,
                'include_in_request' => true,
            ]);
        }
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    public function buildDeterministicMarkdownTable(Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return 'No at-risk items identified based on current forecast and stock.';
        }

        $lines = [];
        $lines[] = '| Priority | Item | Office | Current stock | Forecast/mo | Months of cover | Suggested reorder | Reason |';
        $lines[] = '| --- | --- | --- | ---: | ---: | ---: | ---: | --- |';

        foreach ($rows as $row) {
            $reason = sprintf(
                'Stock %d vs reorder %d; forecast %.1f/mo.',
                (int) $row->current_stock,
                (int) $row->reorder_level,
                (float) $row->forecast_monthly_usage,
            );

            $lines[] = sprintf(
                '| %s | %s | %s | %d | %.1f | %s | %s | %s |',
                $row->priority,
                str_replace('|', '/', (string) $row->item_name),
                str_replace('|', '/', (string) $row->office_name),
                (int) $row->current_stock,
                (float) $row->forecast_monthly_usage,
                $row->months_cover !== null ? number_format((float) $row->months_cover, 1) : '—',
                $row->suggested_reorder_qty !== null ? (string) (int) $row->suggested_reorder_qty : '—',
                str_replace('|', '/', $reason),
            );
        }

        return implode("\n", $lines);
    }

    protected function extractSummaryLine(string $clean): ?string
    {
        $lines = explode("\n", $clean);

        $summaryLines = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') {
                if ($summaryLines !== []) {
                    break;
                }

                continue;
            }
            if (str_starts_with($t, '|')) {
                break;
            }
            $summaryLines[] = $t;
        }

        $summary = Str::limit(implode(' ', $summaryLines), 500);

        return $summary !== '' ? $summary : null;
    }

    public function formatErrorMessage(string $message): string
    {
        if (str_contains($message, 'Maximum execution time') || str_contains($message, 'exceeded')) {
            return 'The request took too long (the model may be slow). Try again, or increase max_execution_time in php.ini.';
        }

        if (preg_match('/cURL error 7|Connection refused|Could not connect to server|Failed to connect/i', $message)) {
            return 'Cannot connect to the local AI server (Ollama). Start Ollama on the operations laptop and keep queue:work running, then try again.';
        }

        return 'An error occurred: '.$message;
    }
}
