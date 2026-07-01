<?php

namespace App\Filament\Pages;

use App\Filament\Resources\AiProcurementRunResource;
use App\Jobs\GenerateAiProcurementRecommendationJob;
use App\Models\AiProcurementRun;
use App\Models\ItemCategory;
use App\Services\AiProcurementRecommendationService;
use App\Services\ProcurementDecisionSupportService;
use App\Support\OwwaExportFilename;
use BackedEnum;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
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

    private const float STOCKOUT_WITHIN_MONTHS = 2.0;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?string $navigationLabel = 'Procurement Analytics';

    protected static ?string $title = 'Procurement Analytics';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.procurement-analytics';

    #[Url]
    public string $categoryId = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $atRiskView = 'all';

    public ?string $recommendation = null;

    public bool $loading = false;

    public ?int $lastAiRunId = null;

    public ?int $processingRunId = null;

    /** @var array{headline: string, priority_actions: array<int, array{item: string, stock: int, suggested: int|null, stock_url: string|null}>, reorder_suggestions: array<int, array{item: string, suggested: int, stock_url: string|null}>}|null */
    public ?array $actionSummary = null;

    public string $sortColumn = 'priority';

    public string $sortDirection = 'asc';

    public function mount(): void
    {
        if ($this->from === '') {
            $this->from = now()->subMonths(11)->startOfMonth()->toDateString();
        }

        if ($this->to === '') {
            $this->to = now()->endOfMonth()->toDateString();
        }

        if (! in_array($this->atRiskView, ['all', 'stockouts'], true)) {
            $this->atRiskView = 'all';
        }
    }

    /**
     * @return array{from: Carbon, to: Carbon}
     */
    protected function resolveDateRange(): array
    {
        $from = $this->from !== ''
            ? Carbon::parse($this->from)->startOfDay()
            : now()->subMonths(11)->startOfMonth();

        $to = $this->to !== ''
            ? Carbon::parse($this->to)->endOfDay()
            : now()->endOfMonth();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return ['from' => $from, 'to' => $to];
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

    public function applyPeriodPreset(string $preset): void
    {
        $dates = $this->presetDateRange($preset);
        if ($dates === null) {
            return;
        }

        $this->clearGeneratedSummary();
        [$this->from, $this->to] = $dates;
    }

    public function updatedFrom(): void
    {
        $this->clearGeneratedSummary();
    }

    public function updatedTo(): void
    {
        $this->clearGeneratedSummary();
    }

    public function updatedCategoryId(): void
    {
        $this->clearGeneratedSummary();
    }

    public function updatedAtRiskView(): void
    {
        $this->clearGeneratedSummary();
    }

    protected function clearGeneratedSummary(): void
    {
        $this->actionSummary = null;
        $this->recommendation = null;
        $this->lastAiRunId = null;
        $this->processingRunId = null;
        $this->loading = false;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    protected function presetDateRange(string $preset): ?array
    {
        $today = now();

        return match ($preset) {
            '3m' => [
                $today->copy()->subMonths(2)->startOfMonth()->toDateString(),
                $today->copy()->endOfMonth()->toDateString(),
            ],
            '6m' => [
                $today->copy()->subMonths(5)->startOfMonth()->toDateString(),
                $today->copy()->endOfMonth()->toDateString(),
            ],
            '12m' => [
                $today->copy()->subMonths(11)->startOfMonth()->toDateString(),
                $today->copy()->endOfMonth()->toDateString(),
            ],
            'ytd' => [
                $today->copy()->startOfYear()->toDateString(),
                $today->copy()->endOfMonth()->toDateString(),
            ],
            default => null,
        };
    }

    public function getActivePeriodPreset(): ?string
    {
        ['from' => $from, 'to' => $to] = $this->resolveDateRange();

        foreach (['3m', '6m', '12m', 'ytd'] as $preset) {
            $dates = $this->presetDateRange($preset);
            if ($dates === null) {
                continue;
            }

            if ($from->toDateString() === $dates[0] && $to->toDateString() === $dates[1]) {
                return $preset;
            }
        }

        return null;
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
    }

    public function getFilterSummary(): string
    {
        $parts = [$this->getPeriodContext()['label']];

        if ($this->categoryId !== '') {
            $parts[] = ItemCategory::find((int) $this->categoryId)?->name ?? 'Category';
        }

        $officeName = Filament::auth()->user()?->office?->name;
        if (filled($officeName)) {
            $parts[] = $officeName;
        }

        return implode(' · ', $parts);
    }

    public function getTitle(): string
    {
        return 'Procurement Analytics';
    }

    public function getHeading(): string|Htmlable|null
    {
        return new HtmlString(
            View::make('filament.pages.partials.procurement-analytics-heading')->render()
        );
    }

    public function getSubheading(): ?string
    {
        return 'Reorder signals from issuances in your selected period and current stock for your office.';
    }

    public static function getNavigationLabel(): string
    {
        return 'Procurement Analytics';
    }

    public function formatAiNarrativeMarkdown(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));
        if ($markdown === '') {
            return '';
        }

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

        return trim(implode("\n", $out));
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

    /** @return Collection<int, ItemCategory> */
    public function getItemCategories(): Collection
    {
        return ItemCategory::orderBy('name')->get();
    }

    /**
     * @return Collection<int, object>
     */
    public function queryAtRiskRows(int $limit = 5000): Collection
    {
        $user = Filament::auth()->user();
        $officeIds = $user?->office_id ? [(int) $user->office_id] : [];

        ['from' => $from, 'to' => $to] = $this->resolveDateRange();

        $categoryId = $this->categoryId !== '' ? (int) $this->categoryId : null;

        return app(ProcurementDecisionSupportService::class)->getAtRiskRows(
            from: $from,
            to: $to,
            categoryId: $categoryId,
            officeIds: $officeIds,
            movingAverageMonths: 6,
            forecastHorizonMonths: 3,
            targetCoverMonths: 3,
            limit: $limit,
        );
    }

    /**
     * @return Collection<int, object>
     */
    public function getAtRiskPreviewRows(): Collection
    {
        return $this->displayAtRiskRows($this->queryAtRiskRows());
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
            $rows = $rows->filter(function ($row): bool {
                if ($row->priority === 'High' && ! ($row->has_recent_usage ?? true)) {
                    return true;
                }

                if (! ($row->has_recent_usage ?? true)) {
                    return false;
                }

                return $row->months_cover !== null
                    && (float) $row->months_cover <= self::STOCKOUT_WITHIN_MONTHS;
            });
        }

        return $this->sortAtRiskRows($rows)->values()->take(self::AT_RISK_LIMIT);
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

        return match ($this->sortColumn) {
            'cover' => $this->sortDirection === 'desc'
                ? $rows->sortByDesc(fn ($row) => $row->months_cover ?? -1)
                : $rows->sortBy(fn ($row) => $row->months_cover ?? 999),
            'stockout' => $this->sortDirection === 'desc'
                ? $rows->sortByDesc(fn ($row) => $row->projected_stockout_date ?? '0000-01-01')
                : $rows->sortBy(fn ($row) => $row->projected_stockout_date ?? '9999-12-31'),
            default => $this->sortDirection === 'desc'
                ? $rows->sortByDesc($priorityRank)
                : $rows->sortBy($priorityRank),
        };
    }

    /** @return array{label: string, from: string, to: string} */
    public function getPeriodContext(): array
    {
        ['from' => $from, 'to' => $to] = $this->resolveDateRange();

        return [
            'label' => $from->format('M j, Y').' – '.$to->format('M j, Y'),
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
        $this->lastAiRunId = null;
        $this->processingRunId = null;

        try {
            $rows = $this->queryAtRiskRows(self::AT_RISK_LIMIT);
            $this->actionSummary = $this->buildProcurementActionSummary($rows);

            ['from' => $from, 'to' => $to] = $this->resolveDateRange();
            $categoryId = $this->categoryId !== '' ? (int) $this->categoryId : null;
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
                categoryId: $categoryId,
                officeIds: $officeIds,
            );

            if (config('queue.default') === 'sync') {
                $this->syncProcessingRun();
            } else {
                Notification::make()
                    ->title('Recommendation queued')
                    ->body('The AI narrative will appear when processing completes.')
                    ->success()
                    ->actions([
                        \Filament\Actions\Action::make('view')
                            ->label('View run')
                            ->url(AiProcurementRunResource::getUrl('view', ['record' => $run->id])),
                    ])
                    ->send();
            }
        } catch (\Throwable $e) {
            $this->recommendation = app(AiProcurementRecommendationService::class)
                ->formatErrorMessage($e->getMessage());
            $this->loading = false;
            $this->processingRunId = null;
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

        if ($run->status === 'failed') {
            $this->recommendation = $run->error_message
                ?? 'AI recommendation failed. Check that the device worker is active on the operation device.';

            Notification::make()
                ->title('AI recommendation failed')
                ->body($this->recommendation)
                ->danger()
                ->send();

            return;
        }

        $this->hydrateRecommendationFromRun($run);

        Notification::make()
            ->title('AI recommendation saved')
            ->body('Review the recommendation below or open the saved run.')
            ->success()
            ->actions([
                \Filament\Actions\Action::make('view')
                    ->label('View run')
                    ->url(AiProcurementRunResource::getUrl('view', ['record' => $run->id])),
            ])
            ->send();
    }

    protected function hydrateRecommendationFromRun(AiProcurementRun $run): void
    {
        $parts = $this->splitRecommendation($run->raw_response);
        $narrative = $parts['narrative'];

        if (str_contains($narrative, 'Ollama is not available')) {
            $this->recommendation = '__OLLAMA_UNAVAILABLE__';

            return;
        }

        $this->recommendation = $narrative !== '' ? $narrative : null;
    }

    public function exportAtRiskCsv(): StreamedResponse
    {
        $rows = $this->displayAtRiskRows($this->queryAtRiskRows());
        $filename = OwwaExportFilename::csvExport('AtRiskProcurement');

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'Priority',
                'Item',
                'Office',
                'Stock',
                'Reorder level',
                'Forecast per month',
                'Months of cover',
                'Projected stockout',
                'Unit cost',
                'Suggested reorder',
                'Recent usage',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->priority,
                    $row->item_name,
                    $row->office_name,
                    $row->current_stock,
                    $row->reorder_level,
                    $row->forecast_monthly_usage,
                    $row->months_cover ?? '',
                    $row->projected_stockout_date ?? '',
                    $row->latest_unit_cost ?? '',
                    $row->suggested_reorder_qty ?? '',
                    ($row->has_recent_usage ?? true) ? 'Yes' : 'No',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
