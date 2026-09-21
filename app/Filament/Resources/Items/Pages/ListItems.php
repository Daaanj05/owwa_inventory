<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Items\Actions\ItemBulkCreateAction;
use App\Filament\Resources\Items\Actions\ItemImportAction;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\ItemCategory;
use App\Support\CategoryWizardBreadcrumb;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

class ListItems extends ListRecords
{
    use HasSystemAdminWizardHeading;
    use SyncsActiveItemCategory;

    #[Url]
    public int|string|null $category = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $importConsumableResult = null;

    /**
     * @var array<string, int>
     */
    public array $importResultsPages = [
        'success' => 1,
        'updated' => 1,
        'skipped' => 1,
        'invalid' => 1,
    ];

    protected static string $resource = ItemResource::class;

    /**
     * Filament schemas sometimes call `getRecord()` even on "list" pages.
     * List pages don't have a selected record, so we return `null`.
     */
    public function getRecord(): mixed
    {
        return null;
    }

    public function mount(): void
    {
        parent::mount();

        $this->syncActiveItemCategoryFromRequest();
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        if ($this->isSystemAdminPanel()) {
            return parent::getHeading();
        }

        $categoryName = ItemCategory::query()->whereKey($this->activeItemCategoryId())->value('name');

        if (! $categoryName) {
            return 'Items';
        }

        return CategoryWizardBreadcrumb::make($categoryName, 'Items', $this->activeItemCategoryId());
    }

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return null;
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('archived_at'))
                ->excludeQueryWhenResolvingRecord(),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('archived_at'))
                ->excludeQueryWhenResolvingRecord(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $createAction = OwwaFormModalDefaults::createActionForResource(ItemResource::class, OwwaFormModalDefaults::WIDTH_COMPACT)
            ->fillForm(fn (): array => [
                'item_category_id' => $this->activeItemCategoryId() ?: null,
            ])
            ->modalHeading('Item')
            ->mutateDataUsing(function (array $data): array {
                $categoryId = $this->activeItemCategoryId();
                if ($categoryId > 0) {
                    $data['item_category_id'] = $categoryId;
                }

                return $data;
            });

        return $schema
            ->components([
                Flex::make([
                    $this->getTabsContentComponent(),
                    Actions::make([
                        ItemImportAction::make()
                            ->extraAttributes(fn (): array => $this->isActiveImportableCategory()
                                ? []
                                : ['class' => 'hidden']),
                        ItemBulkCreateAction::make(),
                        $createAction,
                    ])->alignEnd(),
                ])->alignBetween()->verticallyAlignCenter(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function importConsumableResultsAction(): Action
    {
        return ItemImportAction::resultsAction()
            ->schema(function (): array {
                $result = $this->importConsumableResult;
                if (! is_array($result) || ($result['rows'] ?? []) === []) {
                    return [];
                }

                return ItemImportAction::resultsSchema($result, $this);
            });
    }

    public function importResultsPage(string $tab): int
    {
        return max(1, (int) ($this->importResultsPages[$tab] ?? 1));
    }

    public function resetImportResultsPages(): void
    {
        foreach (ItemImportAction::IMPORT_RESULT_TABS as $tab) {
            $this->importResultsPages[$tab] = 1;
        }
    }

    public function clampImportResultsPage(string $tab, int $totalRows): void
    {
        $totalPages = max(1, (int) ceil($totalRows / ItemImportAction::RESULTS_PER_PAGE));

        if ($this->importResultsPage($tab) > $totalPages) {
            $this->importResultsPages[$tab] = $totalPages;
        }
    }

    public function goToImportResultsPage(string $tab, int $page): void
    {
        if (! in_array($tab, ItemImportAction::IMPORT_RESULT_TABS, true)) {
            return;
        }

        $this->importResultsPages[$tab] = max(1, $page);
    }

    #[On('open-consumable-import-results')]
    public function openConsumableImportResults(): void
    {
        if ($this->importConsumableResult === null) {
            return;
        }

        $this->replaceMountedAction('importConsumableResults');
    }

    public function isActiveImportableCategory(): bool
    {
        $categoryId = $this->activeItemCategoryId();
        if ($categoryId <= 0) {
            $categoryId = (int) session('active_item_category_id', 0);
        }

        if ($categoryId <= 0) {
            return false;
        }

        $category = ItemCategory::query()->find($categoryId);

        return in_array($category?->getTemplateSlug(), ['consumables', 'semi_expendable', 'ppe'], true);
    }

    public function isActiveConsumablesCategory(): bool
    {
        $categoryId = $this->activeItemCategoryId();
        if ($categoryId <= 0) {
            $categoryId = (int) session('active_item_category_id', 0);
        }

        if ($categoryId <= 0) {
            return false;
        }

        $category = ItemCategory::query()->find($categoryId);

        return $category?->getTemplateSlug() === 'consumables';
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        $classes = array_merge(parent::getPageClasses(), ['owwa-items-list-page', 'owwa-wide-table-page']);

        $categoryName = ItemCategory::query()->whereKey($this->activeItemCategoryId())->value('name');

        if (filled($categoryName)) {
            $classes[] = 'owwa-icd--'.\Illuminate\Support\Str::slug($categoryName);
        }

        return $classes;
    }

    public function getTableColumnsSessionKey(): string
    {
        $categoryId = $this->activeItemCategoryId();

        return parent::getTableColumnsSessionKey().'_cat_'.$categoryId;
    }
}
