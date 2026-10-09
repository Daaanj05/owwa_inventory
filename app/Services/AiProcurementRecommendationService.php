<?php

namespace App\Services;

use App\Filament\Pages\ProcurementAnalytics;
use App\Models\AiProcurementItem;
use App\Models\AiProcurementRun;
use App\Models\ItemCategory;
use App\Models\User;
use App\Support\AiProcurementSummaryRestore;
use App\Support\InventoryCategoryOptions;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class AiProcurementRecommendationService
{
    private const int AT_RISK_LIMIT = 25;

    public function __construct(
        protected ProcurementDecisionSupportService $decisionSupport,
        protected RagService $rag,
        protected SemiExpendableEulAnalyticsService $eulAnalytics,
    ) {}

    /**
     * Consumable reorder rows and semi-expendable replacement-due rows for one recommendation.
     *
     * @param  array<int>  $officeIds
     * @param  array<int>  $categoryIds
     * @return array{reorders: Collection<int, object>, replacements: Collection<int, object>}
     */
    public function collectSourceRows(
        Carbon $from,
        Carbon $to,
        ?int $categoryId,
        array $officeIds,
        array $categoryIds = [],
    ): array {
        $reorders = $this->decisionSupport->getAtRiskRows(
            from: $from,
            to: $to,
            categoryId: $categoryId,
            officeIds: $officeIds,
            movingAverageMonths: 6,
            forecastHorizonMonths: 3,
            targetCoverMonths: 3,
            limit: self::AT_RISK_LIMIT,
            categoryIds: $categoryIds,
        );

        $replacements = $this->includesSemiExpendable($categoryId, $categoryIds)
            ? $this->eulAnalytics->getReviewRows($officeIds, self::AT_RISK_LIMIT)
            : collect();

        return [
            'reorders' => $reorders,
            'replacements' => $replacements,
        ];
    }

    /**
     * @param  array<int>  $categoryIds
     */
    protected function includesSemiExpendable(?int $categoryId, array $categoryIds): bool
    {
        $semiIds = InventoryCategoryOptions::categoryIdsForSlug('semi_expendable')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($semiIds === []) {
            return false;
        }

        if ($categoryId === null && $categoryIds === []) {
            return true;
        }

        $requested = $categoryId !== null ? [$categoryId] : array_map('intval', $categoryIds);

        return array_intersect($requested, $semiIds) !== [];
    }

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
     * @param  array<int>  $categoryIds
     */
    public function processRun(
        int $runId,
        string $periodFrom,
        string $periodTo,
        ?int $categoryId,
        array $officeIds,
        array $categoryIds = [],
    ): void {
        $run = AiProcurementRun::query()->findOrFail($runId);

        if ($run->status !== 'processing') {
            return;
        }

        try {
            $from = Carbon::parse($periodFrom)->startOfDay();
            $to = Carbon::parse($periodTo)->endOfDay();

            if ($categoryId === null && $categoryIds === []) {
                $categoryIds = InventoryCategoryOptions::procurementAnalyticsCategoryIds()->all();
            }

            ['reorders' => $rows, 'replacements' => $replacementRows] = $this->collectSourceRows(
                from: $from,
                to: $to,
                categoryId: $categoryId,
                officeIds: $officeIds,
                categoryIds: $categoryIds,
            );

            $categoryName = $categoryId
                ? (ItemCategory::find($categoryId)?->name ?? null)
                : 'Consumables and semi-expendable (excl. PPE)';
            $high = $rows->where('priority', 'High')->count();
            $medium = $rows->where('priority', 'Medium')->count();
            $pairs = $rows->count();

            $headline = $pairs === 0
                ? 'No consumable reorders in this filter'
                : sprintf('%d consumable reorder pairs · %d High · %d Medium', $pairs, $high, $medium);

            $replacementLines = $replacementRows
                ->take(8)
                ->map(fn (object $row): string => sprintf(
                    '- %s (%s): %s, unissued stock %d, action %s',
                    $row->item_name,
                    $row->property_number ?? 'no property number',
                    $row->status_label,
                    (int) $row->unissued_stock,
                    $row->action_label,
                ))
                ->implode("\n");

            $facts = [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'category' => $categoryName,
                'pairs' => $pairs,
                'high' => $high,
                'medium' => $medium,
                'headline' => $headline,
                'replacement_count' => $replacementRows->count(),
                'replacement_lines' => $replacementLines !== '' ? $replacementLines : 'none',
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

            $table = $this->buildDeterministicMarkdownTable($rows, $replacementRows);
            $rawForStorage = $summary === null
                ? 'Ollama is not available. Showing deterministic recommendations without AI narrative summary.'."\n\n".$table
                : trim($summary)."\n\n".$table;

            $this->finalizeRun($run, $rawForStorage, $rows, $replacementRows);
            $this->notifyCreatorOfCompletedRun($run->fresh());
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error_message' => $this->formatErrorMessage($exception->getMessage()),
            ]);
            $this->notifyCreatorOfCompletedRun($run->fresh());
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

        $run = AiProcurementRun::query()->find($runId);
        if ($run !== null) {
            $this->notifyCreatorOfCompletedRun($run);
        }
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  Collection<int, object>  $replacementRows
     */
    public function finalizeRun(AiProcurementRun $run, string $rawResponse, Collection $rows, ?Collection $replacementRows = null): void
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
            'status' => 'pending',
            'error_message' => null,
        ]);

        $run->items()->delete();

        $replacementRows ??= collect();

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

        foreach ($replacementRows as $row) {
            AiProcurementItem::create([
                'run_id' => $run->id,
                'section' => 'replacement',
                'priority' => $row->status === 'expired' ? 'High' : 'Medium',
                'item_name' => $row->item_name,
                'property_number' => $row->property_number,
                'eul_status' => $row->status_label,
                'replacement_action' => $row->action,
                'item_id' => $row->item_id,
                'office_name' => null,
                'office_id' => $row->office_id,
                'current_stock' => (int) $row->unissued_stock,
                'avg_monthly_usage' => null,
                'months_cover' => null,
                'suggested_qty_min' => null,
                'suggested_qty_max' => null,
                'reason' => $row->action_label,
                'include_in_request' => $row->action === SemiExpendableEulAnalyticsService::ACTION_PURCHASE,
            ]);
        }
    }

    protected function notifyCreatorOfCompletedRun(?AiProcurementRun $run): void
    {
        if ($run === null || $run->created_by === null) {
            return;
        }

        $user = User::query()->find($run->created_by);
        if ($user === null) {
            return;
        }

        $analyticsUrl = ProcurementAnalytics::resultUrl();

        AiProcurementSummaryRestore::remember((int) $user->id, (int) $run->id);

        if ($run->status === 'failed') {
            Notification::make()
                ->title('AI recommendation failed')
                ->body($run->error_message ?: 'The recommendation could not be completed.')
                ->danger()
                ->actions([
                    Action::make('viewResult')
                        ->label('View the result')
                        ->url($analyticsUrl)
                        ->markAsRead(),
                ])
                ->sendToDatabase($user);

            return;
        }

        if ($run->status !== 'pending') {
            return;
        }

        Notification::make()
            ->title('AI recommendation ready')
            ->body('Your procurement recommendation is ready.')
            ->success()
            ->actions([
                Action::make('viewResult')
                    ->label('View the result')
                    ->url($analyticsUrl)
                    ->markAsRead(),
            ])
            ->sendToDatabase($user);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  Collection<int, object>|null  $replacementRows
     */
    public function buildDeterministicMarkdownTable(Collection $rows, ?Collection $replacementRows = null): string
    {
        $replacementRows ??= collect();

        if ($rows->isEmpty() && $replacementRows->isEmpty()) {
            return 'No consumable reorders or semi-expendable replacement reviews in this filter.';
        }

        $sections = [];

        if ($rows->isEmpty()) {
            $sections[] = 'No consumable reorders in this filter.';
        } else {
            $sections[] = $this->consumableMarkdownTable($rows);
        }

        $sections[] = $this->replacementMarkdownTable($replacementRows);

        return implode("\n\n", $sections);
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    protected function consumableMarkdownTable(Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return 'No consumable reorders in this filter.';
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

    /**
     * @param  Collection<int, object>  $rows
     */
    protected function replacementMarkdownTable(Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return 'No semi-expendable units are due for replacement review.';
        }

        $lines = [];
        $lines[] = '### Semi-expendable replacement';
        $lines[] = '| Item | Property number | Status | Action |';
        $lines[] = '| --- | --- | --- | --- |';

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s |',
                str_replace('|', '/', (string) $row->item_name),
                str_replace('|', '/', (string) ($row->property_number ?? '—')),
                str_replace('|', '/', (string) $row->status_label),
                str_replace('|', '/', (string) $row->action_label),
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
            return 'Cannot connect to the local AI server (Ollama). Start Ollama on the operation device and ensure the device worker is running, then try again.';
        }

        return 'An error occurred: '.$message;
    }
}
