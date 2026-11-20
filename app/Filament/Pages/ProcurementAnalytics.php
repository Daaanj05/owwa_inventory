<?php

namespace App\Filament\Pages;

use App\Jobs\GenerateAiProcurementRecommendationJob;
use App\Models\AiProcurementRun;
use App\Services\AiProcurementHelperService;
use App\Services\AiProcurementRecommendationService;
use App\Services\AiProcurementRecommenderService;
use App\Services\OllamaClient;
use App\Services\ProcurementDecisionSupportService;
use App\Services\SemiExpendableEulAnalyticsService;
use App\Support\AiProcurementSummaryRestore;
use App\Support\InventoryCategoryOptions;
use App\Support\OwwaExportFilename;
use App\Support\StockCoverPhrase;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ProcurementAnalytics extends Page
{
    private const int AT_RISK_PAGE_SIZE = 25;

    private const float STOCKOUT_WITHIN_MONTHS = 2.0;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?string $navigationLabel = 'Procurement Recommendation';

    protected static ?string $title = 'Procurement Recommendation';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.procurement-analytics';

    #[Url]
    public string $categoryId = '';

    #[Url]
    public string $atRiskView = 'all';

    public string $analyticsSlide = 'reorders';

    public string $recommendationSlide = 'reorders';

    public ?string $recommendation = null;

    public bool $aiNarrativeUnavailable = false;

    public bool $loading = false;

    public ?int $lastAiRunId = null;

    protected ?bool $ollamaAvailable = null;

    public ?int $processingRunId = null;

    /** @var array{headline: string, priority_actions: array<int, array{item: string, stock: int, suggested: int|null, stock_url: string|null}>, reorder_suggestions: array<int, array{item: string, suggested: int, stock_url: string|null}>}|null */
    public ?array $actionSummary = null;

    public string $sortColumn = 'category';

    public string $sortDirection = 'asc';

    public int $atRiskVisibleLimit = self::AT_RISK_PAGE_SIZE;

    public int $atRiskClientOffset = self::AT_RISK_PAGE_SIZE;

    public int $recommendationReorderOffset = self::AT_RISK_PAGE_SIZE;

    public int $recommendationReplacementOffset = self::AT_RISK_PAGE_SIZE;

    /** @var array<string, mixed>|null */
    /** @var array<int, Collection<int, object>> */
    protected array $atRiskRowsCache = [];

    public function mount(): void
    {
        if (! in_array($this->atRiskView, ['all', 'stockouts'], true)) {
            $this->atRiskView = 'all';
        }

        if ($this->categoryId !== '') {
            $allowedIds = InventoryCategoryOptions::procurementAnalyticsCategoryIds()
                ->map(fn ($id): string => (string) $id)
                ->all();
            if (! in_array($this->categoryId, $allowedIds, true)) {
                $this->categoryId = '';
            }
        }

        $this->applyOneShotAnalyticsSlideFromQuery();
        $this->syncAnalyticsSlides();

        $this->restoreRecommendationState();
        $this->stripOneShotQueryParamsFromBrowserUrl();
    }

    protected function applyOneShotAnalyticsSlideFromQuery(): void
    {
        $slide = (string) request()->query('analyticsSlide', '');

        if ($slide === '' || ! in_array($slide, $this->availableAnalyticsSlides(), true)) {
            return;
        }

        $this->analyticsSlide = $slide;
    }

    protected function stripOneShotQueryParamsFromBrowserUrl(): void
    {
        $stripAiRun = request()->has('ai_run');
        $stripAnalyticsSlide = request()->has('analyticsSlide');

        if (! $stripAiRun && ! $stripAnalyticsSlide) {
            return;
        }

        // One-shot deep links / legacy toast params: apply in mount, then keep the bar clean.
        $this->js(<<<'JS'
            (() => {
                const url = new URL(window.location.href);
                let changed = false;
                for (const key of ['ai_run', 'analyticsSlide']) {
                    if (url.searchParams.has(key)) {
                        url.searchParams.delete(key);
                        changed = true;
                    }
                }
                if (! changed) {
                    return;
                }
                const next = url.pathname + url.search + url.hash;
                window.history.replaceState(window.history.state, '', next);
            })();
        JS);
    }

    protected function restoreRecommendationState(): void
    {
        $userId = Auth::id();
        if ($userId === null) {
            return;
        }

        $processing = AiProcurementRun::query()
            ->where('created_by', $userId)
            ->where('status', 'processing')
            ->latest('id')
            ->first();

        if ($processing !== null) {
            $this->processingRunId = $processing->id;
            $this->lastAiRunId = $processing->id;
            $this->loading = true;
            $this->recommendation = null;
            $this->aiNarrativeUnavailable = false;

            return;
        }

        // Keep the active completed run visible across refresh until the user clicks Done.
        $activeRunId = AiProcurementSummaryRestore::active($userId);
        if ($activeRunId === null) {
            return;
        }

        $latest = AiProcurementRun::query()
            ->whereKey($activeRunId)
            ->where('created_by', $userId)
            ->whereIn('status', ['pending', 'failed'])
            ->first();

        if ($latest === null) {
            AiProcurementSummaryRestore::forget($userId);

            return;
        }

        $this->hydrateCompletedRunIntoSummary($latest);
    }

    protected function hydrateCompletedRunIntoSummary(AiProcurementRun $run): void
    {
        $this->lastAiRunId = $run->id;
        $this->processingRunId = null;
        $this->loading = false;

        if (Auth::id() !== null) {
            AiProcurementSummaryRestore::remember((int) Auth::id(), (int) $run->id);
            AiProcurementSummaryRestore::markShown((int) Auth::id(), (int) $run->id);
        }

        if ($run->status === 'failed') {
            $this->aiNarrativeUnavailable = false;
            $this->recommendation = $run->error_message
                ?? 'AI recommendation failed. Check that the device worker is active on the operation device.';

            return;
        }

        $this->hydrateRecommendationFromRun($run);
    }

    public static function resultUrl(?int $runId = null): string
    {
        // Clean path only — focus uses sessionStorage + JS scroll (no visible #hash).
        return static::getUrl(panel: 'admin');
    }

    /**
     * Suggested reorders always use the last 12 calendar months through today.
     *
     * @return array{from: Carbon, to: Carbon}
     */
    protected function resolveDateRange(): array
    {
        return [
            'from' => now()->subMonths(11)->startOfMonth()->startOfDay(),
            'to' => now()->endOfDay(),
        ];
    }

    /**
     * @return array{from: string, to: string}
     */
    public function getSelectedDateRange(): array
    {
        ['from' => $from, 'to' => $to] = $this->resolveDateRange();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    public function updatedCategoryId(): void
    {
        if ($this->categoryId !== '') {
            $allowedIds = InventoryCategoryOptions::procurementAnalyticsCategoryIds()
                ->map(fn ($id): string => (string) $id)
                ->all();

            if (! in_array($this->categoryId, $allowedIds, true)) {
                $this->categoryId = '';
            }
        }

        $this->syncAnalyticsSlides();
        $this->clearGeneratedSummary();
        $this->resetAtRiskWindow();
    }

    public function updatedAtRiskView(): void
    {
        $this->clearGeneratedSummary();
        $this->resetAtRiskWindow();
    }

    protected function clearGeneratedSummary(): void
    {
        $this->actionSummary = null;
        $this->recommendation = null;
        $this->aiNarrativeUnavailable = false;
        $this->lastAiRunId = null;
        $this->processingRunId = null;
        $this->loading = false;
        $this->resetRecommendationOffsets();

        if (Auth::id() !== null) {
            AiProcurementSummaryRestore::forget((int) Auth::id());
        }
    }

    public function dismissRecommendation(): void
    {
        $this->clearGeneratedSummary();
        $this->recommendationSlide = 'reorders';
        $this->syncAnalyticsSlides();
    }

    /**
     * @return list<array{source: string, content: string}>
     */
    public function getPolicySnippets(): array
    {
        if ($this->lastAiRunId === null) {
            return [];
        }

        $run = AiProcurementRun::query()->find($this->lastAiRunId);
        $snippets = $run?->policy_snippets;

        return is_array($snippets) ? array_values($snippets) : [];
    }

    /**
     * @return array<int, string>
     */
    public function availableAnalyticsSlides(): array
    {
        $slides = [];

        if ($this->shouldShowReorderSlide()) {
            $slides[] = 'reorders';
        }

        if ($this->shouldShowEulPanel()) {
            $slides[] = 'replacement';
        }

        return $slides === [] ? ['reorders'] : $slides;
    }

    public function shouldShowReorderSlide(): bool
    {
        return true;
    }

    public function setAnalyticsSlide(string $slide): void
    {
        if (! in_array($slide, $this->availableAnalyticsSlides(), true)) {
            return;
        }

        $this->analyticsSlide = $slide;
    }

    public function shiftAnalyticsSlide(int $step): void
    {
        $this->analyticsSlide = $this->shiftSlide($this->analyticsSlide, $this->availableAnalyticsSlides(), $step);
    }

    public function setRecommendationSlide(string $slide): void
    {
        if (! in_array($slide, $this->availableAnalyticsSlides(), true)) {
            return;
        }

        $this->recommendationSlide = $slide;
    }

    public function shiftRecommendationSlide(int $step): void
    {
        $this->recommendationSlide = $this->shiftSlide($this->recommendationSlide, $this->availableAnalyticsSlides(), $step);
    }

    public function analyticsSlidePositionLabel(): string
    {
        return $this->slidePositionLabel($this->analyticsSlide);
    }

    public function recommendationSlidePositionLabel(): string
    {
        return $this->slidePositionLabel($this->recommendationSlide);
    }

    public function analyticsSlideHeading(): string
    {
        return $this->analyticsSlide === 'replacement'
            ? 'Replacement Due - Semi-Expendable Only'
            : 'Suggested Reorders - Consumables Only';
    }

    public function recommendationSlideHeading(): string
    {
        return $this->recommendationSlide === 'replacement'
            ? 'Recommendation - Semi-Expendable'
            : 'Consumables to Buy';
    }

    protected function syncAnalyticsSlides(): void
    {
        $slides = $this->availableAnalyticsSlides();

        if (! in_array($this->analyticsSlide, $slides, true)) {
            $this->analyticsSlide = $slides[0];
        }

        if (! in_array($this->recommendationSlide, $slides, true)) {
            $this->recommendationSlide = $slides[0];
        }
    }

    /**
     * @param  array<int, string>  $slides
     */
    protected function shiftSlide(string $current, array $slides, int $step): string
    {
        $index = array_search($current, $slides, true);
        if ($index === false || $slides === []) {
            return $slides[0] ?? 'reorders';
        }

        $count = count($slides);
        $next = ($index + $step) % $count;
        if ($next < 0) {
            $next += $count;
        }

        return $slides[$next];
    }

    protected function slidePositionLabel(string $current): string
    {
        $slides = $this->availableAnalyticsSlides();
        $index = array_search($current, $slides, true);
        $position = $index === false ? 1 : $index + 1;

        return $position.' of '.count($slides);
    }

    public function setAtRiskView(string $view): void
    {
        $this->clearGeneratedSummary();
        $this->atRiskView = in_array($view, ['all', 'stockouts'], true) ? $view : 'all';
        $this->resetAtRiskWindow();
    }

    public function sortAtRiskBy(string $column): void
    {
        if (! in_array($column, ['priority', 'cover', 'stockout'], true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetAtRiskWindow();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadMoreAtRiskRows(): array
    {
        $all = $this->displayAtRiskRows(
            $this->queryAtRiskRows(AiProcurementRecommendationService::SOURCE_ROW_LIMIT)
        );
        $total = $all->count();

        if ($this->atRiskClientOffset >= $total) {
            return [];
        }

        $chunk = $all
            ->slice($this->atRiskClientOffset, self::AT_RISK_PAGE_SIZE)
            ->values();

        $this->atRiskClientOffset += $chunk->count();
        $this->atRiskVisibleLimit = $this->atRiskClientOffset;

        return $chunk->map(fn (object $row): array => $this->mapAtRiskRowForClient($row))->all();
    }

    public function hasMoreAtRiskRows(): bool
    {
        return $this->atRiskClientOffset < $this->currentAtRiskTotal();
    }

    protected function resetAtRiskWindow(): void
    {
        $this->atRiskVisibleLimit = self::AT_RISK_PAGE_SIZE;
        $this->atRiskClientOffset = self::AT_RISK_PAGE_SIZE;
        $this->atRiskRowsCache = [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapAtRiskRowForClient(object $row): array
    {
        return [
            'item_id' => (int) $row->item_id,
            'office_id' => (int) $row->office_id,
            'item_name' => (string) $row->item_name,
            'category_name' => (string) ($row->category_name ?? ''),
            'category_sort_rank' => (int) ($row->category_sort_rank ?? 99),
            'current_stock' => (int) $row->current_stock,
            'reorder_level' => (int) $row->reorder_level,
            'forecast_monthly_usage' => (float) $row->forecast_monthly_usage,
            'months_cover' => $row->months_cover !== null ? (float) $row->months_cover : null,
            'suggested_reorder_qty' => $row->suggested_reorder_qty !== null ? (int) $row->suggested_reorder_qty : null,
            'projected_stockout_date' => $row->projected_stockout_date,
            'latest_unit_cost' => $row->latest_unit_cost !== null ? (float) $row->latest_unit_cost : null,
            'priority' => (string) $row->priority,
        ];
    }

    protected function currentAtRiskTotal(): int
    {
        return $this->atRiskView === 'stockouts'
            ? $this->getStockoutPreviewCount()
            : $this->getAtRiskPreviewCount();
    }

    public function getFilterSummary(): string
    {
        $parts = [$this->getPeriodContext()['label']];

        $officeName = Filament::auth()->user()?->office?->name;
        if (filled($officeName)) {
            $parts[] = $officeName;
        }

        return implode(' · ', $parts);
    }

    public function getSummaryScopeLabel(): string
    {
        return 'All (excl. PPE)';
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\AiProcurementItem> */
    public function getRecommendationTableRows(): Collection
    {
        if ($this->lastAiRunId === null) {
            return collect();
        }

        $run = AiProcurementRun::query()
            ->with([
                'items.item.category:id,name',
            ])
            ->find($this->lastAiRunId);

        return ($run?->items ?? collect())
            ->sortBy([
                fn ($row): int => $row->section === 'replacement' ? 1 : 0,
                fn ($row): int => match ($row->priority) {
                    'High' => 0,
                    'Medium' => 1,
                    default => 2,
                },
                fn ($row): string => strtolower((string) ($row->item_name ?? '')),
            ])
            ->values();
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\AiProcurementItem> */
    public function getRecommendationReorderRows(): Collection
    {
        $rows = $this->getRecommendationTableRows()
            ->filter(fn ($row): bool => $row->section !== 'replacement')
            ->values();

        $rankedIds = $this->getAiRecommendation()['ranked_high_item_ids'] ?? [];
        if (! is_array($rankedIds) || $rankedIds === []) {
            return $rows;
        }

        $rank = [];
        foreach (array_values($rankedIds) as $index => $id) {
            $rank[(int) $id] = $index;
        }

        return $rows
            ->sort(function ($a, $b) use ($rank): int {
                $aHigh = (string) ($a->priority ?? '') === 'High';
                $bHigh = (string) ($b->priority ?? '') === 'High';
                if ($aHigh !== $bHigh) {
                    return $aHigh ? -1 : 1;
                }
                if ($aHigh && $bHigh) {
                    $aRank = $rank[(int) ($a->item_id ?? 0)] ?? 1000;
                    $bRank = $rank[(int) ($b->item_id ?? 0)] ?? 1000;
                    if ($aRank !== $bRank) {
                        return $aRank <=> $bRank;
                    }
                }

                return strtolower((string) ($a->item_name ?? '')) <=> strtolower((string) ($b->item_name ?? ''));
            })
            ->values();
    }

    /**
     * @return array{next_action?: string, ranked_high_item_ids?: list<int>, why_by_item_id?: array<string, string>, flags_by_item_id?: array<string, list<string>>, source?: string}
     */
    public function getAiRecommendation(): array
    {
        if ($this->lastAiRunId === null) {
            return [];
        }

        $run = AiProcurementRun::query()->find($this->lastAiRunId);
        $helpers = is_array($run?->helpers) ? $run->helpers : [];
        $rec = $helpers[AiProcurementRecommenderService::HELPER_KEY] ?? null;

        return is_array($rec) ? $rec : [];
    }

    public function getAiNextAction(): string
    {
        return trim((string) ($this->getAiRecommendation()['next_action'] ?? ''));
    }

    public function getAiRecommendationSource(): string
    {
        return (string) ($this->getAiRecommendation()['source'] ?? 'fallback');
    }

    /**
     * @return list<string>
     */
    public function getAiInsightSummaryParts(): array
    {
        $flagsById = $this->getAiRecommendation()['flags_by_item_id'] ?? null;
        if (! is_array($flagsById) || $flagsById === []) {
            $flagsById = [];
            foreach ($this->getRecommendationReorderRows()->where('priority', 'High') as $row) {
                $id = $row->item_id !== null ? (int) $row->item_id : 0;
                if ($id === 0) {
                    continue;
                }
                $flagsById[(string) $id] = is_array($row->insight_flags) ? $row->insight_flags : [];
            }
        }

        $review = 0;
        $fileSoon = 0;
        foreach ($flagsById as $flags) {
            if (! is_array($flags)) {
                continue;
            }
            if (in_array('review_forecast', $flags, true)) {
                $review++;
            }
            if (in_array('file_pr_soon', $flags, true)) {
                $fileSoon++;
            }
        }

        $parts = [];
        if ($review > 0) {
            $parts[] = $review === 1
                ? '1 item needs forecast review'
                : "{$review} items need forecast review";
        }
        if ($fileSoon > 0) {
            $parts[] = $fileSoon === 1
                ? '1 item needs PR soon'
                : "{$fileSoon} items need PR soon";
        }

        return $parts;
    }

    public function getAiInsightSummaryMeta(): string
    {
        $parts = $this->getAiInsightSummaryParts();

        return $parts === [] ? '' : 'Insights: '.implode(' · ', $parts);
    }

    public function getAiSemiNextAction(): string
    {
        return trim((string) ($this->getAiRecommendation()['semi_next_action'] ?? ''));
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\AiProcurementItem> */
    public function getRecommendationReplacementRows(): Collection
    {
        return $this->getRecommendationTableRows()
            ->filter(fn ($row): bool => $row->section === 'replacement')
            ->values();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecommendationReorderInitialRows(): array
    {
        return $this->mapRecommendationItemsForClient(
            $this->getRecommendationReorderRows()->take(self::AT_RISK_PAGE_SIZE)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecommendationReplacementInitialRows(): array
    {
        return $this->mapRecommendationItemsForClient(
            $this->getRecommendationReplacementRows()->take(self::AT_RISK_PAGE_SIZE)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadMoreRecommendationReorderRows(): array
    {
        $all = $this->getRecommendationReorderRows();
        if ($this->recommendationReorderOffset >= $all->count()) {
            return [];
        }

        $chunk = $all->slice($this->recommendationReorderOffset, self::AT_RISK_PAGE_SIZE)->values();
        $this->recommendationReorderOffset += $chunk->count();

        return $this->mapRecommendationItemsForClient($chunk);
    }

    /**
     * Lazy-fetch next reorder detail page when Why / What to buy opens (scales with large runs).
     *
     * @return list<array<string, mixed>>
     */
    public function ensureRecommendationDetailRows(): array
    {
        return $this->loadMoreRecommendationReorderRows();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadMoreRecommendationReplacementRows(): array
    {
        $all = $this->getRecommendationReplacementRows();
        if ($this->recommendationReplacementOffset >= $all->count()) {
            return [];
        }

        $chunk = $all->slice($this->recommendationReplacementOffset, self::AT_RISK_PAGE_SIZE)->values();
        $this->recommendationReplacementOffset += $chunk->count();

        return $this->mapRecommendationItemsForClient($chunk);
    }

    /**
     * @param  Collection<int, \App\Models\AiProcurementItem>  $rows
     * @return list<array<string, mixed>>
     */
    protected function mapRecommendationItemsForClient(Collection $rows): array
    {
        $helper = app(AiProcurementHelperService::class);
        $aiWhy = $this->getAiRecommendation()['why_by_item_id'] ?? [];
        if (! is_array($aiWhy)) {
            $aiWhy = [];
        }
        $flagsById = $this->getAiRecommendation()['flags_by_item_id'] ?? [];
        if (! is_array($flagsById)) {
            $flagsById = [];
        }

        return $rows->map(function ($row) use ($helper, $aiWhy, $flagsById): array {
            $usage = $row->avg_monthly_usage !== null ? (float) $row->avg_monthly_usage : null;
            $isReplacement = (string) ($row->section ?? '') === 'replacement';
            $daysRemaining = $row->eul_days_remaining !== null ? (int) $row->eul_days_remaining : null;
            $lifeRemaining = $isReplacement ? $helper->lifeRemainingPhrase($daysRemaining) : '';
            $eulLabel = filled($row->estimated_useful_life)
                ? 'EUL '.(string) $row->estimated_useful_life
                : '';
            $property = (string) ($row->property_number ?? '');
            $semiMeta = $isReplacement
                ? implode(' · ', array_values(array_filter([
                    $property !== '' ? $property : null,
                    $eulLabel !== '' ? $eulLabel : null,
                    $lifeRemaining !== '' ? $lifeRemaining : null,
                ])))
                : '';
            $itemId = $row->item_id !== null ? (int) $row->item_id : null;
            $aiExplanation = ($itemId !== null && isset($aiWhy[(string) $itemId]))
                ? trim((string) $aiWhy[(string) $itemId])
                : '';
            $explanation = '';
            if (! $isReplacement && (string) ($row->priority ?? '') === 'High') {
                $explanation = $aiExplanation !== '' ? $aiExplanation : $helper->explainHighItem($row);
            }

            $insightFlags = [];
            if ($itemId !== null && isset($flagsById[(string) $itemId]) && is_array($flagsById[(string) $itemId])) {
                $insightFlags = array_values($flagsById[(string) $itemId]);
            } elseif (is_array($row->insight_flags ?? null)) {
                $insightFlags = array_values($row->insight_flags);
            }

            $insightFlagLabels = [];
            foreach ($insightFlags as $flag) {
                if (! in_array($flag, AiProcurementRecommenderService::WHY_DISPLAY_FLAGS, true)) {
                    continue;
                }
                $label = AiProcurementRecommenderService::FLAG_LABELS[$flag] ?? null;
                if (is_string($label) && $label !== '') {
                    $insightFlagLabels[] = $label;
                }
            }

            return [
                'item_id' => $itemId,
                'priority' => (string) ($row->priority ?? ''),
                'item_name' => (string) ($row->item_name ?? ''),
                'current_stock' => $row->current_stock !== null ? (int) $row->current_stock : null,
                'suggested_qty_min' => $row->suggested_qty_min !== null ? (int) $row->suggested_qty_min : null,
                'months_cover' => $row->months_cover !== null ? (float) $row->months_cover : null,
                'cover_phrase' => $row->months_cover !== null
                    ? StockCoverPhrase::fromMonths((float) $row->months_cover)
                    : '',
                'usage_phrase' => $helper->usagePhrase($usage),
                'why_explanation' => $explanation !== '' ? $explanation : 'Needs reorder attention',
                'insight_flags' => $insightFlags,
                'insight_flag_labels' => $insightFlagLabels,
                'property_number' => $property,
                'estimated_useful_life' => (string) ($row->estimated_useful_life ?? ''),
                'eul_label' => $eulLabel,
                'life_remaining_phrase' => $lifeRemaining,
                'semi_meta' => $semiMeta !== '' ? $semiMeta : '—',
                'eul_status' => (string) ($row->eul_status ?? ''),
                'reason' => (string) ($row->reason ?? ''),
                'next_step' => $isReplacement ? $helper->nextStepForReplacement($row) : '',
                'section' => (string) ($row->section ?? ''),
            ];
        })->values()->all();
    }

    public function resetRecommendationOffsets(): void
    {
        $this->recommendationReorderOffset = self::AT_RISK_PAGE_SIZE;
        $this->recommendationReplacementOffset = self::AT_RISK_PAGE_SIZE;
    }

    public function getLastGeneratedLabel(): ?string
    {
        if ($this->lastAiRunId === null) {
            return null;
        }

        $run = AiProcurementRun::query()->find($this->lastAiRunId);
        $timestamp = $run?->ran_at ?? $run?->updated_at;

        return $timestamp?->format('M j, Y g:i A');
    }

    public function isOllamaAvailable(): bool
    {
        if ($this->ollamaAvailable === null) {
            $this->ollamaAvailable = app(OllamaClient::class)->isAvailable();
        }

        return $this->ollamaAvailable;
    }

    public function getTitle(): string
    {
        return 'Procurement Recommendation';
    }

    public function getHeading(): string|Htmlable|null
    {
        return new HtmlString(
            View::make('filament.pages.partials.procurement-analytics-heading')->render()
        );
    }

    public function getSubheading(): ?string
    {
        return 'Suggested reorders are for consumables. Semi-expendable units nearing or past useful life are a replacement review. Property, plant, and equipment are excluded.';
    }

    public static function getNavigationLabel(): string
    {
        return 'Procurement Recommendation';
    }

    /**
     * @return array{consumables: string, replacement: string}
     */
    public function splitSlideNarratives(?string $narrative): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim((string) $narrative));
        if ($text === '') {
            return ['consumables' => '', 'replacement' => ''];
        }

        $extract = function (string $label) use ($text): string {
            $pattern = '/\[\[\s*'.preg_quote($label, '/').'\s*\]\]\s*(.*?)(?=\[\[\s*(?:NEXT ACTION|CONSUMABLES|SEMI-EXPENDABLE)\s*\]\]|\z)/is';
            if (preg_match($pattern, $text, $matches) === 1) {
                return trim($matches[1]);
            }

            return '';
        };

        $nextAction = $extract('NEXT ACTION');
        $consumables = $extract('CONSUMABLES');
        $replacement = $extract('SEMI-EXPENDABLE');

        $hasMarkers = preg_match('/\[\[\s*(?:NEXT ACTION|CONSUMABLES|SEMI-EXPENDABLE)\s*\]\]/i', $text) === 1;

        if (! $hasMarkers) {
            return [
                'consumables' => trim($text),
                'replacement' => '',
            ];
        }

        if ($nextAction !== '') {
            $consumables = $nextAction.($consumables !== '' ? "\n\n".$consumables : '');
        }

        return [
            'consumables' => $consumables,
            'replacement' => $replacement,
        ];
    }

    public function recommendationNarrativeForSlide(string $slide): string
    {
        $parts = $this->splitSlideNarratives(
            $this->splitRecommendation($this->recommendation)['narrative']
        );

        if ($slide === 'replacement') {
            $text = $parts['replacement'] !== ''
                ? $parts['replacement']
                : $this->deterministicReplacementNarrative();

            return StockCoverPhrase::rewriteDecimalMonthsInText($text);
        }

        $text = $parts['consumables'] !== ''
            ? $parts['consumables']
            : $this->deterministicConsumablesNarrative();

        return StockCoverPhrase::rewriteDecimalMonthsInText($text);
    }

    public function deterministicConsumablesNarrative(): string
    {
        $rows = $this->getRecommendationReorderRows()->take(5);
        if ($rows->isEmpty()) {
            return 'No consumables need reorder attention in this filter.';
        }

        $lines = $rows->map(function (object $row): string {
            $item = trim((string) ($row->item_name ?? 'Item'));
            $stock = $row->current_stock !== null ? (string) (int) $row->current_stock : '—';
            $buy = $row->suggested_qty_min !== null ? (string) (int) $row->suggested_qty_min : '—';
            $cover = StockCoverPhrase::fromMonths((float) ($row->months_cover ?? 0));

            return "- {$item} — stock {$stock} → buy {$buy} · {$cover}";
        })->all();

        return "Consumables to reorder this cycle:\n".implode("\n", $lines);
    }

    public function deterministicReplacementNarrative(): string
    {
        $rows = $this->getRecommendationReplacementRows()->take(4);
        if ($rows->isEmpty()) {
            return 'No semi-expendable units are due for replacement review in this filter.';
        }

        $lines = $rows->map(function (object $row): string {
            $item = trim((string) ($row->item_name ?? 'Unit'));
            $status = trim((string) ($row->eul_status ?? '—'));
            $actionCode = trim((string) ($row->replacement_action ?? ''));
            $actionLabel = trim((string) ($row->reason ?? ''));

            $nextStep = app(AiProcurementHelperService::class)->nextStepForReplacement((object) [
                'replacement_action' => $actionCode,
                'reason' => $actionLabel,
            ]);

            return "- **{$item}** — {$status} → {$nextStep}";
        })->all();

        return "Semi-expendable units to review:\n".implode("\n", $lines);
    }

    public function formatAiNarrativeMarkdown(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));
        if ($markdown === '') {
            return '';
        }

        $markdown = preg_replace('/\s+@\s+[^.,;]+/u', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/\[\[\s*(?:NEXT ACTION|CONSUMABLES|SEMI-EXPENDABLE)\s*\]\]/i', '', $markdown) ?? $markdown;
        $markdown = StockCoverPhrase::rewriteDecimalMonthsInText($markdown);
        $markdown = preg_replace(
            '/(?:^|\n)\s*["“”\']*\s*One opening line\s*:?\s*/iu',
            "\n",
            $markdown,
        ) ?? $markdown;

        // Models sometimes echo prompt scaffolding, e.g. Sentence 2 must be: "..."
        $markdown = preg_replace(
            '/(?:^|\n)\s*["“”\']*\s*Sentence\s+\d+\s*(?:must\s+be|should\s+be|is)?\s*:\s*["“”\']*/iu',
            "\n",
            $markdown,
        ) ?? $markdown;
        $markdown = preg_replace(
            '/^\s*["“”\']*\s*Sentence\s+\d+\s*(?:must\s+be|should\s+be|is)?\s*:\s*["“”\']*/iu',
            '',
            $markdown,
        ) ?? $markdown;

        $markdown = $this->stripRedundantNarrativeQuotes(trim($markdown));

        $lines = explode("\n", $markdown);
        $normalized = [];

        foreach ($lines as $rawLine) {
            $line = rtrim($rawLine);
            $trimmed = trim($line);
            if ($trimmed === '') {
                $normalized[] = '';

                continue;
            }

            $trimmed = preg_replace('/^["“”\']+|["“”\']+$/u', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^[•●◦–—]\s+/u', $trimmed) === 1) {
                $trimmed = '- '.preg_replace('/^[•●◦–—]\s+/u', '', $trimmed);
            } elseif (
                ! preg_match('/^([-*] |\d+\.\s+)/', $trimmed)
                && preg_match('/^.+\s+[—–→\-]+\s+.+/u', $trimmed) === 1
            ) {
                $trimmed = '- '.$trimmed;
            }

            $normalized[] = $trimmed;
        }

        $out = [];

        $isBlockLine = static function (string $line): bool {
            $t = ltrim($line);

            return $t === ''
                || str_starts_with($t, '- ')
                || str_starts_with($t, '* ')
                || (bool) preg_match('/^\d+\.\s+/', $t)
                || str_starts_with($t, '|')
                || str_starts_with($t, '>')
                || str_starts_with($t, '```');
        };

        for ($i = 0; $i < count($normalized); $i++) {
            $line = $normalized[$i];
            $out[] = $line;

            $next = $normalized[$i + 1] ?? null;
            if ($next === null) {
                continue;
            }

            if ($line === '' || trim($next) === '') {
                continue;
            }

            if (! $isBlockLine($line) && ! $isBlockLine($next)) {
                $out[] = '';
            }
        }

        return $this->stripRedundantNarrativeQuotes(trim(implode("\n", $out)));
    }

    /**
     * CSS already draws decorative quotes around the narrative; strip model-added wrappers.
     */
    protected function stripRedundantNarrativeQuotes(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        // Whole-string wrappers: "text" or “text”
        if (preg_match('/^["“](.+)["”]$/su', $markdown, $matches) === 1) {
            $markdown = trim($matches[1]);
        }

        // Leftover leading/trailing quote characters after label stripping.
        $markdown = preg_replace('/^["“”\']+/u', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/["“”\']+$/u', '', $markdown) ?? $markdown;

        return trim($markdown);
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? false;
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return ['owwa-pa-page'];
    }

    /**
     * Both lists stay on the page. Property, plant, and equipment stay out of the reorder query.
     *
     * @return array{categoryId: int|null, categoryIds: array<int>}
     */
    public function resolveProcurementCategoryScope(): array
    {
        return [
            'categoryId' => null,
            'categoryIds' => InventoryCategoryOptions::procurementAnalyticsCategoryIds()->all(),
        ];
    }

    public function shouldShowEulPanel(): bool
    {
        return true;
    }

    /**
     * @return Collection<int, object>
     */
    public function getEulReviewRows(): Collection
    {
        if (! $this->shouldShowEulPanel()) {
            return collect();
        }

        $user = Filament::auth()->user();
        $officeIds = $user?->office_id ? [(int) $user->office_id] : [];

        return app(SemiExpendableEulAnalyticsService::class)->getReviewRows(
            officeIds: $officeIds,
            limit: self::AT_RISK_PAGE_SIZE,
        );
    }

    /**
     * @return Collection<int, object>
     */
    public function queryAtRiskRows(int $limit = AiProcurementRecommendationService::SOURCE_ROW_LIMIT): Collection
    {
        $user = Filament::auth()->user();
        $officeIds = $user?->office_id ? [(int) $user->office_id] : [];

        if (isset($this->atRiskRowsCache[$limit])) {
            return $this->atRiskRowsCache[$limit];
        }

        ['from' => $from, 'to' => $to] = $this->resolveDateRange();
        $scope = $this->resolveProcurementCategoryScope();

        return $this->atRiskRowsCache[$limit] = app(ProcurementDecisionSupportService::class)->getAtRiskRows(
            from: $from,
            to: $to,
            categoryId: $scope['categoryId'],
            officeIds: $officeIds,
            movingAverageMonths: 6,
            forecastHorizonMonths: 3,
            targetCoverMonths: 3,
            limit: $limit,
            categoryIds: $scope['categoryIds'],
        );
    }

    /**
     * @return Collection<int, object>
     */
    public function getAtRiskPreviewRows(): Collection
    {
        return $this->displayAtRiskRows(
            $this->queryAtRiskRows(AiProcurementRecommendationService::SOURCE_ROW_LIMIT)
        )
            ->take(self::AT_RISK_PAGE_SIZE)
            ->values();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAtRiskInitialClientRows(): array
    {
        return $this->getAtRiskPreviewRows()
            ->map(fn (object $row): array => $this->mapAtRiskRowForClient($row))
            ->all();
    }

    public function getAtRiskPreviewCount(): int
    {
        return $this->displayAtRiskRows($this->queryAtRiskRows(), 'all')->count();
    }

    public function getStockoutPreviewCount(): int
    {
        return $this->displayAtRiskRows($this->queryAtRiskRows(), 'stockouts')->count();
    }

    /**
     * @return array{
     *   headline: string,
     *   priority_actions: array<int, array{item: string, stock: int, suggested: int|null, stock_url: string|null}>,
     *   reorder_suggestions: array<int, array{item: string, suggested: int, stock_url: string|null}>
     * }
     */
    public function buildProcurementActionSummary(Collection $rows): array
    {
        $high = $rows->where('priority', 'High')->count();
        $medium = $rows->where('priority', 'Medium')->count();
        $pairs = $rows->count();

        $headline = $pairs === 0
            ? 'No at-risk pairs in this filter'
            : sprintf('%d at-risk pairs · %d High · %d Medium', $pairs, $high, $medium);

        $priorityActions = $rows
            ->where('priority', 'High')
            ->take(8)
            ->map(fn ($row) => [
                'item' => (string) $row->item_name,
                'stock' => (int) $row->current_stock,
                'suggested' => $row->suggested_reorder_qty,
                'stock_url' => isset($row->item_category_id)
                    ? StockLevels::getUrl(['category' => $row->item_category_id])
                    : null,
            ])
            ->values()
            ->all();

        $reorderSuggestions = $rows
            ->where('priority', 'Medium')
            ->filter(fn ($row) => ($row->suggested_reorder_qty ?? 0) > 0)
            ->take(8)
            ->map(fn ($row) => [
                'item' => (string) $row->item_name,
                'suggested' => (int) $row->suggested_reorder_qty,
                'stock_url' => isset($row->item_category_id)
                    ? StockLevels::getUrl(['category' => $row->item_category_id])
                    : null,
            ])
            ->values()
            ->all();

        return [
            'headline' => $headline,
            'priority_actions' => $priorityActions,
            'reorder_suggestions' => $reorderSuggestions,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    public function getActionSummaryRows(): Collection
    {
        return $this->queryAtRiskRows(AiProcurementRecommendationService::SOURCE_ROW_LIMIT)
            ->take(self::AT_RISK_PAGE_SIZE)
            ->values();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    protected function displayAtRiskRows(Collection $rows, ?string $view = null): Collection
    {
        $view ??= $this->atRiskView;

        if ($view === 'stockouts') {
            $rows = $rows->filter(fn ($row): bool => $row->months_cover !== null
                && (float) $row->months_cover <= self::STOCKOUT_WITHIN_MONTHS);
        }

        return $this->sortAtRiskRows($rows)->values();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    protected function sortAtRiskRows(Collection $rows): Collection
    {
        $priorityRank = static fn (object $row): int => match ($row->priority) {
            'High' => 0,
            'Medium' => 1,
            default => 2,
        };

        $categoryRank = static fn (object $row): int => (int) ($row->category_sort_rank ?? 99);
        $categoryName = static fn (object $row): string => strtolower((string) ($row->category_name ?? ''));
        $itemKey = static fn (object $row): string => strtolower((string) ($row->item_name ?? ''));

        $byCategory = static function (object $a, object $b) use ($categoryRank, $categoryName, $priorityRank, $itemKey): int {
            return [$categoryRank($a), $categoryName($a), $priorityRank($a), $itemKey($a)]
                <=> [$categoryRank($b), $categoryName($b), $priorityRank($b), $itemKey($b)];
        };

        return match ($this->sortColumn) {
            'cover' => $rows->sort(function (object $a, object $b) use ($byCategory): int {
                $coverA = $a->months_cover ?? ($this->sortDirection === 'desc' ? -1 : 999);
                $coverB = $b->months_cover ?? ($this->sortDirection === 'desc' ? -1 : 999);
                $coverCmp = $this->sortDirection === 'desc'
                    ? ($coverB <=> $coverA)
                    : ($coverA <=> $coverB);

                return $coverCmp !== 0 ? $coverCmp : $byCategory($a, $b);
            }),
            'stockout' => $rows->sort(function (object $a, object $b) use ($byCategory): int {
                $stockoutA = $a->projected_stockout_date ?? ($this->sortDirection === 'desc' ? '0000-01-01' : '9999-12-31');
                $stockoutB = $b->projected_stockout_date ?? ($this->sortDirection === 'desc' ? '0000-01-01' : '9999-12-31');
                $stockoutCmp = $this->sortDirection === 'desc'
                    ? ($stockoutB <=> $stockoutA)
                    : ($stockoutA <=> $stockoutB);

                return $stockoutCmp !== 0 ? $stockoutCmp : $byCategory($a, $b);
            }),
            'priority' => $rows->sort(function (object $a, object $b) use ($priorityRank, $byCategory): int {
                $priorityCmp = $this->sortDirection === 'desc'
                    ? ($priorityRank($b) <=> $priorityRank($a))
                    : ($priorityRank($a) <=> $priorityRank($b));

                return $priorityCmp !== 0 ? $priorityCmp : $byCategory($a, $b);
            }),
            default => $rows->sort($byCategory),
        };
    }

    /** @return array{label: string, from: string, to: string} */
    public function getPeriodContext(): array
    {
        ['from' => $from, 'to' => $to] = $this->resolveDateRange();

        return [
            'label' => 'Last 12 months through today',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    /**
     * @return array{narrative: string, table: string}
     */
    public function splitRecommendation(?string $text): array
    {
        if ($text === null || $text === '') {
            return ['narrative' => '', 'table' => ''];
        }

        $clean = preg_replace('/<think>.*?<\/think>/s', '', $text);
        $clean = str_replace(["\r\n", "\r"], "\n", trim((string) $clean));

        $headerPos = strpos($clean, '| Priority |');
        if ($headerPos === false) {
            return ['narrative' => $clean, 'table' => ''];
        }

        $lineStart = strrpos(substr($clean, 0, $headerPos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        return [
            'narrative' => trim(substr($clean, 0, $lineStart)),
            'table' => trim(substr($clean, $lineStart)),
        ];
    }

    public function generateAiRecommendation(): void
    {
        // Close any visible prior result first (same as Done) so regenerate does not leave stale AI DOM.
        $hadVisibleResult = filled($this->recommendation) || $this->lastAiRunId !== null;

        $this->actionSummary = null;
        $this->recommendation = null;
        $this->aiNarrativeUnavailable = false;
        $this->lastAiRunId = null;
        $this->processingRunId = null;
        $this->recommendationSlide = 'reorders';
        $this->resetRecommendationOffsets();
        $this->loading = true;

        if (Auth::id() !== null) {
            AiProcurementSummaryRestore::forget((int) Auth::id());
        }

        if ($hadVisibleResult) {
            $this->js('window.owwaCollapseProcurementRecommendationResult?.()');
        }

        try {
            ['from' => $from, 'to' => $to] = $this->resolveDateRange();
            $scope = $this->resolveProcurementCategoryScope();
            $officeIds = Filament::auth()->user()?->office_id ? [(int) Filament::auth()->user()->office_id] : [];

            $run = app(AiProcurementRecommendationService::class)->createProcessingRun(
                from: $from,
                to: $to,
                createdBy: Auth::id(),
            );

            $this->processingRunId = $run->id;
            $this->lastAiRunId = $run->id;
            $this->resetRecommendationOffsets();

            GenerateAiProcurementRecommendationJob::dispatch(
                runId: $run->id,
                periodFrom: $from->toDateString(),
                periodTo: $to->toDateString(),
                categoryId: $scope['categoryId'],
                officeIds: $officeIds,
                categoryIds: $scope['categoryIds'],
            );

            if (config('queue.default') === 'sync') {
                $this->syncProcessingRun();
            }
        } catch (\Throwable $e) {
            $this->recommendation = app(AiProcurementRecommendationService::class)
                ->formatErrorMessage($e->getMessage());
            $this->aiNarrativeUnavailable = false;
            $this->loading = false;
            $this->processingRunId = null;
            $this->js(AiProcurementSummaryRestore::browserAnnounceScript([
                'title' => 'AI recommendation failed',
                'body' => $this->recommendation,
                'danger' => true,
                'seconds' => 10,
                'actionLabel' => 'View the result',
                'actionUrl' => static::resultUrl(),
            ]));
        }
    }

    public function syncProcessingRun(): void
    {
        if ($this->processingRunId === null) {
            return;
        }

        $run = AiProcurementRun::query()->find($this->processingRunId);
        if ($run === null) {
            $this->processingRunId = null;
            $this->loading = false;

            return;
        }

        if ($run->status === 'processing') {
            return;
        }

        $this->processingRunId = null;
        $this->loading = false;
        $this->lastAiRunId = $run->id;

        if (Auth::id() !== null) {
            AiProcurementSummaryRestore::remember((int) Auth::id(), (int) $run->id);
            AiProcurementSummaryRestore::markShown((int) Auth::id(), (int) $run->id);
        }

        if ($run->status === 'failed') {
            $this->aiNarrativeUnavailable = false;
            $this->recommendation = $run->error_message
                ?? 'AI recommendation failed. Check that the device worker is active on the operation device.';

            if (AiProcurementSummaryRestore::claimSessionToast($run->id)) {
                $this->js(AiProcurementSummaryRestore::browserAnnounceScript([
                    'title' => 'AI recommendation failed',
                    'body' => $this->recommendation,
                    'danger' => true,
                    'seconds' => 10,
                    'actionLabel' => 'View the result',
                    'actionUrl' => static::resultUrl(),
                ]));
            }

            return;
        }

        $this->hydrateRecommendationFromRun($run);

        $shouldToast = AiProcurementSummaryRestore::claimSessionToast($run->id);

        $this->js(AiProcurementSummaryRestore::browserAnnounceScript([
            'silent' => ! $shouldToast,
            'title' => 'AI recommendation ready',
            'body' => 'Your procurement recommendation is ready on this page.',
            'seconds' => 10,
            'actionLabel' => 'View the result',
            'actionUrl' => static::resultUrl(),
        ]));
    }

    protected function hydrateRecommendationFromRun(AiProcurementRun $run): void
    {
        $this->resetRecommendationOffsets();
        $this->lastAiRunId = (int) $run->id;

        $parts = $this->splitRecommendation($run->raw_response);
        $narrative = $parts['narrative'];

        if (str_contains($narrative, 'Ollama is not available')) {
            $this->aiNarrativeUnavailable = true;
            $summary = trim((string) ($run->summary ?? ''));
            $this->recommendation = ($summary !== '' && ! str_contains($summary, 'Ollama is not available'))
                ? $summary
                : 'Deterministic recommendation ready from the consumable reorder and replacement-due lists.';

            return;
        }

        $this->aiNarrativeUnavailable = false;
        $this->recommendation = $narrative !== '' ? $narrative : null;
    }

    public function exportAtRiskPdf(?string $view = null): StreamedResponse
    {
        if ($this->analyticsSlide === 'replacement') {
            return $this->streamListPdf(
                'reports.replacement-due',
                'Replacement Due - Semi-Expendable Only',
                OwwaExportFilename::pdfExport('ReplacementDue'),
                $this->getEulReviewRows(),
            );
        }

        $exportView = in_array($view, ['all', 'stockouts'], true) ? $view : $this->atRiskView;

        return $this->streamListPdf(
            'reports.suggested-reorders',
            'Suggested Reorders - Consumables Only',
            OwwaExportFilename::pdfExport('SuggestedReorders'),
            $this->displayAtRiskRows($this->queryAtRiskRows(), $exportView),
        );
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    protected function streamListPdf(string $view, string $title, string $filename, Collection $rows): StreamedResponse
    {
        $pdf = Pdf::loadView($view, [
            'title' => $title,
            'filterSummary' => $this->getFilterSummary(),
            'generatedAt' => now()->format('M j, Y g:i A'),
            'rows' => $rows,
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
