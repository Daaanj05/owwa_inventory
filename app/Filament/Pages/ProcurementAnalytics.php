<?php

namespace App\Filament\Pages;

use App\Jobs\GenerateAiProcurementRecommendationJob;
use App\Models\AiProcurementRun;
use App\Services\AiProcurementRecommendationService;
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
    private const int AT_RISK_LIMIT = 25;

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
        return static::getUrl(panel: 'admin').'#procurement-summary';
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

    public function loadMoreAtRiskRows(): void
    {
        $total = $this->currentAtRiskTotal();

        if ($this->atRiskVisibleLimit >= $total) {
            return;
        }

        $this->atRiskVisibleLimit = min(
            $this->atRiskVisibleLimit + self::AT_RISK_PAGE_SIZE,
            $total,
        );
    }

    public function hasMoreAtRiskRows(): bool
    {
        return $this->atRiskVisibleLimit < $this->currentAtRiskTotal();
    }

    protected function resetAtRiskWindow(): void
    {
        $this->atRiskVisibleLimit = self::AT_RISK_PAGE_SIZE;
        $this->atRiskRowsCache = [];
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
        return $this->getRecommendationTableRows()
            ->filter(fn ($row): bool => $row->section !== 'replacement')
            ->values();
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\AiProcurementItem> */
    public function getRecommendationReplacementRows(): Collection
    {
        return $this->getRecommendationTableRows()
            ->filter(fn ($row): bool => $row->section === 'replacement')
            ->values();
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

        if (preg_match('/\[\[CONSUMABLES\]\]\s*(.*?)\s*\[\[SEMI-EXPENDABLE\]\]\s*(.*)\z/is', $text, $matches) === 1) {
            return [
                'consumables' => trim($matches[1]),
                'replacement' => trim($matches[2]),
            ];
        }

        if (preg_match('/\[\[SEMI-EXPENDABLE\]\]\s*(.*?)\s*\[\[CONSUMABLES\]\]\s*(.*)\z/is', $text, $matches) === 1) {
            return [
                'consumables' => trim($matches[2]),
                'replacement' => trim($matches[1]),
            ];
        }

        $text = preg_replace('/\[\[(?:CONSUMABLES|SEMI-EXPENDABLE)\]\]/i', '', $text) ?? $text;

        return [
            'consumables' => trim($text),
            'replacement' => '',
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
            : trim((string) $this->recommendation);

        return StockCoverPhrase::rewriteDecimalMonthsInText($text);
    }

    public function deterministicReplacementNarrative(): string
    {
        $rows = $this->getRecommendationReplacementRows()->take(4);
        if ($rows->isEmpty()) {
            return 'No semi-expendable units are due for replacement review in this filter.';
        }

        $mentions = $rows->map(function (object $row): string {
            $item = trim((string) ($row->item_name ?? 'Unit'));
            $action = trim((string) ($row->reason ?? 'Awaiting review'));

            return $action !== '' ? "{$item} ({$action})" : $item;
        })->all();

        $list = count($mentions) === 1
            ? $mentions[0]
            : implode(', ', array_slice($mentions, 0, -1)).', and '.$mentions[array_key_last($mentions)];

        return "Kindly review these semi-expendable units due for replacement: {$list}.";
    }

    public function formatAiNarrativeMarkdown(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));
        if ($markdown === '') {
            return '';
        }

        $markdown = preg_replace('/\s+@\s+[^.,;]+/u', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/\[\[(?:CONSUMABLES|SEMI-EXPENDABLE)\]\]/i', '', $markdown) ?? $markdown;
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

        for ($i = 0; $i < count($lines); $i++) {
            $line = rtrim($lines[$i]);
            $out[] = $line;

            $next = $lines[$i + 1] ?? null;
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
            limit: self::AT_RISK_LIMIT,
        );
    }

    /**
     * @return Collection<int, object>
     */
    public function queryAtRiskRows(int $limit = 5000): Collection
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
        return $this->displayAtRiskRows($this->queryAtRiskRows())
            ->take($this->atRiskVisibleLimit)
            ->values();
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
        return $this->queryAtRiskRows(self::AT_RISK_LIMIT);
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
        $this->loading = true;
        $this->recommendation = null;
        $this->aiNarrativeUnavailable = false;
        $this->lastAiRunId = null;
        $this->processingRunId = null;

        try {
            $rows = $this->queryAtRiskRows(self::AT_RISK_LIMIT);

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
                'actionUrl' => '#procurement-summary',
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
                    'actionUrl' => '#procurement-summary',
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
            'actionUrl' => '#procurement-summary',
        ]));
    }

    protected function hydrateRecommendationFromRun(AiProcurementRun $run): void
    {
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
