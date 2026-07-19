<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\StartsOwwaExportBusy;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\StockPositionRestockFlag;
use App\Services\InventoryStockService;
use App\Services\OwwaItemReportService;
use App\Services\StockLedgerViewService;
use App\Services\StockLevelExportService;
use App\Support\UnitCostKey;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

class StockLevels extends Page
{
    use StartsOwwaExportBusy;
    use SyncsActiveItemCategory;
    use WithPagination;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|UnitEnum|null $navigationGroup = 'Regional supply';

    protected static ?string $navigationLabel = 'Stock levels';

    protected static ?string $title = 'Stock levels';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.stock-levels';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian() ?? false;
    }

    #[Url]
    public string $sortBy = 'item_name';

    #[Url]
    public string $sortDir = 'asc';

    #[Url]
    public string $search = '';

    #[Url]
    public int|string|null $category = null;

    #[Url]
    public string $restockFilter = 'active';

    public ?ItemCategory $categoryRecord = null;

    /** @var array<int, string> */
    public array $selectedKeys = [];

    public function mount(): void
    {
        $categoryId = filled($this->category)
            ? (int) $this->category
            : (int) session('active_item_category_id', 0);

        $categoryId = self::resolveActiveItemCategoryId($categoryId);

        $this->categoryRecord = ItemCategory::query()->find($categoryId);

        if (! $this->categoryRecord) {
            abort(404);
        }

        $this->category = $this->categoryRecord->id;
        session()->put('active_item_category_id', $this->categoryRecord->id);

        if (! in_array($this->restockFilter, ['active', 'inactive'], true)) {
            $this->restockFilter = 'active';
        }
    }

    public function getTitle(): string|Htmlable
    {
        return 'Stock levels';
    }

    public function getHeading(): string|Htmlable
    {
        $categoryName = $this->categoryRecord?->name;

        return $categoryName
            ? new HtmlString($this->getWizardHeaderBreadcrumb($categoryName, 'Stock Levels'))
            : 'Stock levels';
    }

    public static function getNavigationLabel(): string
    {
        return 'Stock levels';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function buildExportDownloadsActionGroup(): ActionGroup
    {
        $slug = $this->categoryRecord?->getTemplateSlug() ?? 'consumables';

        $actions = [
            Action::make('coaStockLevel')
                ->label('Summary PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(route('reports.coa.stock-level'))
                ->openUrlInNewTab(false),
            $this->makeStockCardsExportAction(
                name: 'exportStockCardsExcel',
                label: match ($slug) {
                    'ppe' => 'Property cards (Excel)',
                    'semi_expendable' => 'Annex A.1 (Excel)',
                    default => 'Stock cards (Excel)',
                },
                format: 'xlsx',
            ),
            $this->makeStockCardsExportAction(
                name: 'exportStockCardsPdf',
                label: match ($slug) {
                    'ppe' => 'Property cards (PDF)',
                    'semi_expendable' => 'Annex A.1 (PDF)',
                    default => 'Stock cards (PDF)',
                },
                format: 'pdf',
            ),
        ];

        if ($this->categoryRecord?->getTemplateSlug() === 'semi_expendable') {
            $actions[] = $this->makeAnnexA4ExportAction(
                name: 'exportAnnexA4Excel',
                label: 'Annex A.4 (Excel)',
                format: 'xlsx',
            );
            $actions[] = $this->makeAnnexA4ExportAction(
                name: 'exportAnnexA4Pdf',
                label: 'Annex A.4 (PDF)',
                format: 'pdf',
            );
        }

        return ActionGroup::make($actions)
            ->label('Export / Download')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->button()
            ->dropdownWidth(Width::MaxContent)
            ->livewire($this);
    }

    /**
     * Filament resolves {name}Action() with zero arguments when mounting group actions.
     */
    public function exportAnnexA4ExcelAction(): Action
    {
        return $this->makeAnnexA4ExportAction(
            name: 'exportAnnexA4Excel',
            label: 'Annex A.4 (Excel)',
            format: 'xlsx',
        );
    }

    public function exportAnnexA4PdfAction(): Action
    {
        return $this->makeAnnexA4ExportAction(
            name: 'exportAnnexA4Pdf',
            label: 'Annex A.4 (PDF)',
            format: 'pdf',
        );
    }

    protected function makeAnnexA4ExportAction(string $name, string $label, string $format): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->action(function () use ($format): void {
                $url = route('owwa.export.bulk.annex-a4', array_filter([
                    'category' => $this->category,
                    'search' => filled($this->search) ? $this->search : null,
                    'restock_filter' => $this->restockFilter !== 'active' ? $this->restockFilter : null,
                    'format' => $format === 'pdf' ? 'pdf' : null,
                ]));

                $this->startOwwaExportDownload(
                    $url,
                    $format === 'pdf' ? 'Preparing PDF export…' : 'Preparing Excel export…',
                    $format === 'pdf'
                        ? 'Building Annex A.4 registry pages…'
                        : 'Building Annex A.4 registry workbook…',
                );
            });
    }

    public function exportStockCardsExcelAction(): Action
    {
        $slug = $this->categoryRecord?->getTemplateSlug() ?? 'consumables';

        return $this->makeStockCardsExportAction(
            name: 'exportStockCardsExcel',
            label: match ($slug) {
                'ppe' => 'Property cards (Excel)',
                'semi_expendable' => 'Annex A.1 (Excel)',
                default => 'Stock cards (Excel)',
            },
            format: 'xlsx',
        );
    }

    public function exportStockCardsPdfAction(): Action
    {
        $slug = $this->categoryRecord?->getTemplateSlug() ?? 'consumables';

        return $this->makeStockCardsExportAction(
            name: 'exportStockCardsPdf',
            label: match ($slug) {
                'ppe' => 'Property cards (PDF)',
                'semi_expendable' => 'Annex A.1 (PDF)',
                default => 'Stock cards (PDF)',
            },
            format: 'pdf',
        );
    }

    protected function makeStockCardsExportAction(string $name, string $label, string $format): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->modalHeading($label)
            ->modalSubmitActionLabel('Download')
            ->form([
                Placeholder::make('selection_hint')
                    ->label('')
                    ->content(fn (): string => $this->selectedKeys === []
                        ? 'No rows selected. Choose “All filtered rows” or select rows from the table first. Selections are kept across pages.'
                        : count($this->selectedKeys).' row(s) selected across page(s).')
                    ->columnSpanFull(),
                Radio::make('export_scope')
                    ->label('Rows to export')
                    ->options([
                        'selected' => 'Selected rows only',
                        'all' => 'All filtered rows',
                    ])
                    ->default($this->selectedKeys !== [] ? 'selected' : 'all')
                    ->required()
                    ->live(),
            ])
            ->action(function (array $data, Action $action) use ($format): void {
                $scope = (string) ($data['export_scope'] ?? 'all');

                if ($scope === 'selected' && $this->selectedKeys === []) {
                    \Filament\Notifications\Notification::make()
                        ->title('No rows selected')
                        ->body('Select at least one row from the table, or export all filtered rows.')
                        ->warning()
                        ->send();

                    $action->halt();

                    return;
                }

                $url = $this->buildStockCardsExportUrl($scope, $format);
                $this->startOwwaExportDownload(
                    $url,
                    $format === 'pdf' ? 'Preparing PDF export…' : 'Preparing Excel export…',
                    $format === 'pdf'
                        ? 'Building OWWA form pages. Large selections can take a little while.'
                        : 'Building your workbook. Large selections can take a little while.',
                );
            });
    }

    public function buildStockCardsExportUrl(string $scope, string $format): string
    {
        $params = array_filter([
            'category' => $this->category,
            'search' => filled($this->search) ? $this->search : null,
            'restock_filter' => $this->restockFilter !== 'active' ? $this->restockFilter : null,
            'format' => $format === 'pdf' ? 'pdf' : null,
            'pairs' => $scope === 'selected' ? implode(',', $this->selectedKeys) : null,
        ], fn (mixed $value): bool => filled($value));

        return route('owwa.export.bulk.stock-cards', $params);
    }

    public function pairKeyForRow(object $row): string
    {
        return app(StockLevelExportService::class)->encodePairKey(
            (int) $row->item_id,
            (int) $row->office_id,
            isset($row->unit_cost) ? (float) $row->unit_cost : null,
        );
    }

    /**
     * @return array<int, string>
     */
    public function currentPagePairKeys(): array
    {
        return $this->getStockLevels()
            ->getCollection()
            ->map(fn (object $row): string => $this->pairKeyForRow($row))
            ->values()
            ->all();
    }

    public function toggleRowSelection(string $key): void
    {
        if (in_array($key, $this->selectedKeys, true)) {
            $this->selectedKeys = array_values(array_filter(
                $this->selectedKeys,
                fn (string $selected): bool => $selected !== $key,
            ));

            return;
        }

        $this->selectedKeys[] = $key;
    }

    public function toggleSelectAllOnPage(): void
    {
        $pageKeys = $this->currentPagePairKeys();

        $allSelected = $pageKeys !== []
            && collect($pageKeys)->every(fn (string $key): bool => in_array($key, $this->selectedKeys, true));

        if ($allSelected) {
            $this->selectedKeys = array_values(array_filter(
                $this->selectedKeys,
                fn (string $key): bool => ! in_array($key, $pageKeys, true),
            ));

            return;
        }

        $this->selectedKeys = array_values(array_unique([
            ...$this->selectedKeys,
            ...$pageKeys,
        ]));
    }

    public function isRowSelected(string $key): bool
    {
        return in_array($key, $this->selectedKeys, true);
    }

    public function isAllOnPageSelected(): bool
    {
        $pageKeys = $this->currentPagePairKeys();

        return $pageKeys !== []
            && collect($pageKeys)->every(fn (string $key): bool => in_array($key, $this->selectedKeys, true));
    }

    public function getSelectedCount(): int
    {
        return count($this->selectedKeys);
    }

    public function clearSelection(): void
    {
        $this->selectedKeys = [];
    }

    public function getMissingPropertyClassCount(): int
    {
        if ($this->categoryRecord?->getTemplateSlug() !== 'semi_expendable') {
            return 0;
        }

        return app(OwwaItemReportService::class)->countStockLevelItemsMissingPropertyClass(
            (int) $this->category,
            filled($this->search) ? $this->search : null,
        );
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        if (! $this->categoryRecord) {
            return ['owwa-inv-category-page'];
        }

        return [
            'owwa-inv-category-page',
            'owwa-icd--'.Str::slug($this->categoryRecord->name),
        ];
    }

    protected function getWizardHeaderBreadcrumb(string $categoryName, string $taskLabel): string
    {
        $dashboardUrl = InventoryCategoryDashboard::getUrl(['category' => (int) $this->category]);

        return sprintf(
            '<span class="owwa-wizard-title" role="list"><a class="owwa-wizard-step owwa-wizard-step-link" href="%s" role="listitem">%s</a><span class="owwa-wizard-separator" aria-hidden="true">&gt;</span><span class="owwa-wizard-step owwa-wizard-step-current" role="listitem">%s</span></span>',
            e($dashboardUrl),
            e($categoryName),
            e($taskLabel),
        );
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selectedKeys = [];
    }

    public function setRestockFilter(string $filter): void
    {
        if (! in_array($filter, ['active', 'inactive'], true)) {
            return;
        }

        $this->restockFilter = $filter;
        $this->resetPage();
        $this->selectedKeys = [];
    }

    public function sortByColumn(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    /** @return array{total: int, totalStockQty: int, lowCount: int, okCount: int} */
    public function getStockLevelsSummary(): array
    {
        $rows = $this->getStockLevelsFull();
        $total = $rows->count();
        $lowCount = $rows->where('is_low', true)->count();

        return [
            'total' => $total,
            'totalStockQty' => (int) $rows->sum('stock'),
            'lowCount' => $lowCount,
            'okCount' => $total - $lowCount,
        ];
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public function getStockLevelsFull(): \Illuminate\Support\Collection
    {
        $rows = app(InventoryStockService::class)->getStockLevelsList();
        $user = Filament::auth()->user();
        if ($user && $user->office_id) {
            $rows = $rows->where('office_id', (int) $user->office_id)->values();
        }

        if ($this->categoryRecord) {
            $rows = $rows->where('category_name', $this->categoryRecord->name)->values();
        }

        if (filled($this->search)) {
            $term = mb_strtolower($this->search);
            $rows = $rows->filter(fn (object $r): bool => str_contains(mb_strtolower($r->item_name ?? ''), $term)
                || str_contains(mb_strtolower($r->office_name ?? ''), $term)
            )->values();
        }

        $rows = $rows->filter(fn (object $row): bool => $this->matchesRestockFilter($row))->values();

        if (in_array($this->categoryRecord?->getTemplateSlug(), ['ppe', 'semi_expendable'], true)) {
            $taggedCounts = $this->taggedUnitCountsForRows($rows);
            $rows = $rows->map(function (object $row) use ($taggedCounts): object {
                $key = UnitCostKey::positionKey(
                    (int) $row->item_id,
                    (int) $row->office_id,
                    isset($row->unit_cost) ? (float) $row->unit_cost : null,
                );
                $row->accountable_tags = (int) ($taggedCounts[$key] ?? 0);
                $row->tagged_units = $row->accountable_tags;
                $row->tagged_drift = $row->accountable_tags < (int) $row->stock;

                return $row;
            });
        }

        return $rows;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<string, int>
     */
    protected function taggedUnitCountsForRows(\Illuminate\Support\Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $itemIds = $rows->pluck('item_id')->unique()->values();
        $officeIds = $rows->pluck('office_id')->unique()->values();

        $counts = InventoryUnit::query()
            ->selectRaw('item_id, office_id, COALESCE(unit_cost, 0) as unit_cost, count(*) as tagged_units')
            ->whereIn('item_id', $itemIds)
            ->whereIn('office_id', $officeIds)
            ->whereIn('status', InventoryUnit::accountableStatuses())
            ->groupBy('item_id', 'office_id', DB::raw('COALESCE(unit_cost, 0)'))
            ->get();

        $result = [];
        foreach ($counts as $count) {
            $key = UnitCostKey::positionKey(
                (int) $count->item_id,
                (int) $count->office_id,
                (float) $count->unit_cost,
            );
            $result[$key] = (int) $count->tagged_units;
        }

        return $result;
    }

    public function usesTaggedUnitsColumn(): bool
    {
        return in_array($this->categoryRecord?->getTemplateSlug(), ['ppe', 'semi_expendable'], true);
    }

    public function shouldShowSupplyCustodianScopeFilters(): bool
    {
        return false;
    }

    public function getStockLevels(): LengthAwarePaginator
    {
        $rows = $this->getStockLevelsFull();

        $sortBy = $this->sortBy;
        $sortDir = $this->sortDir;
        $rows = $rows->sortBy($sortBy, SORT_REGULAR, $sortDir === 'desc')->values();

        $perPage = 10;
        $page = $this->getPage();

        return (new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $this->stockLevelsPaginationPath()],
        ))
            ->appends($this->stockLevelsPaginationAppends())
            ->onEachSide(0);
    }

    protected function stockLevelsPaginationPath(): string
    {
        return static::getUrl(['category' => $this->category]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function stockLevelsPaginationAppends(): array
    {
        return array_filter([
            'category' => $this->category,
            'sortBy' => $this->sortBy,
            'sortDir' => $this->sortDir,
            'search' => filled($this->search) ? $this->search : null,
            'restockFilter' => $this->restockFilter !== 'active' ? $this->restockFilter : null,
        ], fn (mixed $value): bool => filled($value));
    }

    protected function matchesRestockFilter(object $row): bool
    {
        $isInactive = (bool) ($row->is_inactive_for_restock ?? false);

        return $this->restockFilter === 'inactive' ? $isInactive : ! $isInactive;
    }

    public function openStockLedger(int $itemId, int $officeId, float|string|null $unitCost = null): void
    {
        $parsedCost = $unitCost !== null && $unitCost !== '' ? (float) $unitCost : null;

        try {
            app(StockLedgerViewService::class)->assertVisibleInStockList(
                $itemId,
                $officeId,
                $this->getStockLevelsFull(),
                $parsedCost,
            );
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->mountAction('viewStockLedger', [
            'itemId' => $itemId,
            'officeId' => $officeId,
            'unitCost' => $parsedCost,
        ]);
    }

    public function toggleRestockInactive(int $itemId, int $officeId, float|string $unitCost): void
    {
        $user = Filament::auth()->user();
        if (! $user || ($user->office_id && (int) $user->office_id !== $officeId)) {
            abort(403);
        }

        StockPositionRestockFlag::markInactive(
            $itemId,
            $officeId,
            (float) $unitCost,
            (int) $user->id,
        );

        \Filament\Notifications\Notification::make()
            ->title('Marked inactive for restock')
            ->body('This cost position remains in inventory but is excluded from procurement cover.')
            ->success()
            ->send();
    }

    public function toggleRestockActive(int $itemId, int $officeId, float|string $unitCost): void
    {
        $user = Filament::auth()->user();
        if (! $user || ($user->office_id && (int) $user->office_id !== $officeId)) {
            abort(403);
        }

        $stock = app(\App\Services\InventoryStockService::class)
            ->getStockForUnitCost($itemId, $officeId, (float) $unitCost);
        $flag = StockPositionRestockFlag::findForPosition($itemId, $officeId, (float) $unitCost);
        $snooze = $stock <= 0 && $flag?->inactive_source === StockPositionRestockFlag::SOURCE_AUTOMATIC;

        StockPositionRestockFlag::markActive(
            $itemId,
            $officeId,
            (float) $unitCost,
            snoozeAutomaticIfStillZero: $snooze,
        );

        \Filament\Notifications\Notification::make()
            ->title('Marked active for restock')
            ->success()
            ->send();
    }

    public function getTransferPrefillUrl(int $itemId, int $officeId, float|string|null $unitCost = null): string
    {
        return TransferResource::getUrl('index', array_filter([
            'create' => 1,
            'item_id' => $itemId,
            'from_office' => $officeId,
            'category' => (int) $this->category,
            'unit_cost' => $unitCost !== null && $unitCost !== '' ? (float) $unitCost : null,
        ], fn (mixed $v): bool => $v !== null && $v !== ''));
    }

    public function canCreateTransfer(): bool
    {
        if ($this->categoryRecord?->getTemplateSlug() === 'consumables') {
            return false;
        }

        return TransferResource::canViewAny();
    }

    public function viewStockLedgerAction(): Action
    {
        return Action::make('viewStockLedger')
            ->modalWidth(Width::FiveExtraLarge)
            ->extraModalWindowAttributes(['class' => 'owwa-view-record-modal'])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalHeading(function (): string {
                $ledger = $this->resolveMountedLedger();

                return $ledger['title'].' — '.$ledger['header']['item_name'];
            })
            ->modalContent(fn (): HtmlString => new HtmlString(view(
                'filament.pages.partials.stock-ledger-modal',
                ['ledger' => $this->resolveMountedLedger()],
            )->render()))
            ->extraModalFooterActions(function (): array {
                $ledger = $this->resolveMountedLedger();
                $actions = [
                    Action::make('exportLedgerExcel')
                        ->label($ledger['exportLabel'])
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('gray')
                        ->action(function () use ($ledger): void {
                            $this->startOwwaExportDownload(
                                $ledger['exportUrl'],
                                'Preparing Excel export…',
                                'Building your '.$ledger['title'].' workbook…',
                            );
                        }),
                    Action::make('exportLedgerPdf')
                        ->label($ledger['exportPdfLabel'])
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('gray')
                        ->action(function () use ($ledger): void {
                            $this->startOwwaExportDownload(
                                $ledger['exportPdfUrl'],
                                'Preparing PDF export…',
                                'Building your '.$ledger['title'].' PDF…',
                            );
                        }),
                ];

                return $actions;
            });
    }

    /**
     * @return array{
     *     title: string,
     *     exportForm: string,
     *     exportLabel: string,
     *     exportUrl: string,
     *     exportPdfLabel: string,
     *     exportPdfUrl: string,
     *     header: array<string, string|null>,
     *     columns: array<string, string>,
     *     rows: array<int, array<string, mixed>>
     * }
     */
    protected function resolveMountedLedger(): array
    {
        $arguments = $this->getMountedAction()?->getArguments() ?? [];
        $itemId = (int) ($arguments['itemId'] ?? 0);
        $officeId = (int) ($arguments['officeId'] ?? 0);
        $unitCost = isset($arguments['unitCost']) && $arguments['unitCost'] !== null
            ? (float) $arguments['unitCost']
            : null;

        $item = Item::query()->with('category')->findOrFail($itemId);
        $office = Office::query()->findOrFail($officeId);

        return app(StockLedgerViewService::class)->present($item, $office, $unitCost);
    }
}
