<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Pages\InventoryCategoryDashboard;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\ItemCategory;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

class ListItems extends ListRecords
{
    use HasSystemAdminWizardHeading;
    use SyncsActiveItemCategory;

    #[Url]
    public int|string|null $category = null;

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

        return new HtmlString($this->getWizardHeaderBreadcrumb($categoryName, 'Items'));
    }

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return null;
    }

    protected function getWizardHeaderBreadcrumb(string $categoryName, string $taskLabel): string
    {
        $categoryId = $this->activeItemCategoryId();
        $dashboardUrl = InventoryCategoryDashboard::getUrl(['category' => $categoryId]);

        return sprintf(
            '<span class="owwa-wizard-title" role="list"><a class="owwa-wizard-step owwa-wizard-step-link" href="%s" role="listitem">%s</a><span class="owwa-wizard-separator" aria-hidden="true">&gt;</span><span class="owwa-wizard-step owwa-wizard-step-current" role="listitem">%s</span></span>',
            e($dashboardUrl),
            e($categoryName),
            e($taskLabel),
        );
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
        return $schema
            ->components([
                Flex::make([
                    $this->getTabsContentComponent(),
                    Actions::make([
                        OwwaFormModalDefaults::createActionForResource(ItemResource::class, OwwaFormModalDefaults::WIDTH_COMPACT)
                            ->fillForm(fn (): array => [
                                'item_category_id' => $this->activeItemCategoryId() ?: null,
                            ])
                            ->mutateDataUsing(function (array $data): array {
                                $categoryId = $this->activeItemCategoryId();
                                if ($categoryId > 0) {
                                    $data['item_category_id'] = $categoryId;
                                }

                                return $data;
                            }),
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

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return array_merge(parent::getPageClasses(), ['owwa-items-list-page', 'owwa-wide-table-page']);
    }

    public function getTableColumnsSessionKey(): string
    {
        $categoryId = $this->activeItemCategoryId();

        return parent::getTableColumnsSessionKey().'_cat_'.$categoryId;
    }
}
