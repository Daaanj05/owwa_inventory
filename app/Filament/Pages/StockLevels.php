<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\InventoryStockService;
use App\Services\OwwaItemReportService;
use App\Services\StockLedgerViewService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

class StockLevels extends Page
{
    use SyncsActiveItemCategory;
    use WithPagination;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Stock levels';

    protected static ?string $title = 'Stock levels';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.stock-levels';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user && ! $user->isSystemAdmin() && ! $user->isEmployee();
    }

    #[Url]
    public string $sortBy = 'item_name';

    #[Url]
    public string $sortDir = 'asc';

    #[Url]
    public string $search = '';

    #[Url]
    public int|string|null $category = null;

    public ?ItemCategory $categoryRecord = null;

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
        $actions = [
            Action::make('coaStockLevel')
                ->label('Summary PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(route('reports.coa.stock-level'))
                ->openUrlInNewTab(false),
        ];

        if ($this->categoryRecord?->getTemplateSlug() === 'semi_expendable') {
            $actions[] = Action::make('exportAnnexA1')
                ->label('Export Annex A.1 property cards (XLS)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(route('owwa.export.bulk.annex-a1', array_filter([
                    'category' => $this->category,
                    'search' => filled($this->search) ? $this->search : null,
                ])))
                ->openUrlInNewTab(false);

            $actions[] = Action::make('exportAnnexA4')
                ->label('Export Annex A.4 registry (XLS)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(route('owwa.export.bulk.annex-a4', array_filter([
                    'category' => $this->category,
                    'search' => filled($this->search) ? $this->search : null,
                ])))
                ->openUrlInNewTab(false);
        }

        if ($this->categoryRecord?->getTemplateSlug() === 'ppe') {
            $actions[] = Action::make('exportPropertyCards')
                ->label('Export Property Cards (XLS)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(route('owwa.export.bulk.property-cards', array_filter([
                    'category' => $this->category,
                    'search' => filled($this->search) ? $this->search : null,
                ])));
        }

        return ActionGroup::make($actions)
            ->label('Export / Download')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->button()
            ->livewire($this);
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
                || str_contains(mb_strtolower($r->category_name ?? ''), $term)
                || str_contains(mb_strtolower($r->office_name ?? ''), $term)
            )->values();
        }

        if (in_array($this->categoryRecord?->getTemplateSlug(), ['ppe', 'semi_expendable'], true)) {
            $taggedCounts = $this->taggedUnitCountsForRows($rows);
            $rows = $rows->map(function (object $row) use ($taggedCounts): object {
                $key = "{$row->item_id}_{$row->office_id}";
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
            ->selectRaw('item_id, office_id, count(*) as tagged_units')
            ->whereIn('item_id', $itemIds)
            ->whereIn('office_id', $officeIds)
            ->whereIn('status', InventoryUnit::accountableStatuses())
            ->groupBy('item_id', 'office_id')
            ->get();

        $result = [];
        foreach ($counts as $count) {
            $result["{$count->item_id}_{$count->office_id}"] = (int) $count->tagged_units;
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
        ], fn (mixed $value): bool => filled($value));
    }

    public function openStockLedger(int $itemId, int $officeId): void
    {
        try {
            app(StockLedgerViewService::class)->assertVisibleInStockList(
                $itemId,
                $officeId,
                $this->getStockLevelsFull(),
            );
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->mountAction('viewStockLedger', [
            'itemId' => $itemId,
            'officeId' => $officeId,
        ]);
    }

    public function getTransferPrefillUrl(int $itemId, int $officeId): string
    {
        return TransferResource::getUrl('index', [
            'create' => 1,
            'item_id' => $itemId,
            'from_office' => $officeId,
            'category' => (int) $this->category,
        ]);
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

                return [
                    Action::make('exportLedger')
                        ->label($ledger['exportLabel'])
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('gray')
                        ->url($ledger['exportUrl'])
                        ->openUrlInNewTab(false),
                ];
            });
    }

    /**
     * @return array{
     *     title: string,
     *     exportForm: string,
     *     exportLabel: string,
     *     exportUrl: string,
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

        $item = Item::query()->with('category')->findOrFail($itemId);
        $office = Office::query()->findOrFail($officeId);

        return app(StockLedgerViewService::class)->present($item, $office);
    }
}
