<?php

namespace App\Filament\Resources\Issuances\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\StartsOwwaExportBusy;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Pages\InventoryCategoryDashboard;
use App\Filament\Resources\Issuances\Concerns\IssuanceRsmiExportAction;
use App\Filament\Resources\Issuances\IssuanceResource;
use App\Filament\Resources\Pages\ListRecordsWithoutFilterUrl;
use App\Models\ItemCategory;
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

class ListIssuances extends ListRecordsWithoutFilterUrl
{
    use HasSystemAdminWizardHeading;
    use StartsOwwaExportBusy;
    use SyncsActiveItemCategory;

    #[Url]
    public int|string|null $category = null;

    protected static string $resource = IssuanceResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return 'Issuances';
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        $categoryName = ItemCategory::query()->whereKey($this->activeItemCategoryId())->value('name');

        if (! $categoryName) {
            return 'Issuances';
        }

        return new HtmlString($this->getWizardHeaderBreadcrumb($categoryName, 'Issuances'));
    }

    /**
     * Filament schemas sometimes call `getRecord()` even on "list" pages.
     * List pages don't have a selected record, so we return `null`.
     */
    public function getRecord(): mixed
    {
        return null;
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

    public function mount(): void
    {
        parent::mount();

        $this->syncActiveItemCategoryFromRequest();

        if (($this->activeTab ?? null) === 'all' || ($this->activeTab ?? null) === 'today_rsmi') {
            $this->activeTab = 'active';
        }
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        $classes = parent::getPageClasses();
        $classes[] = 'owwa-issuances-list';

        if ($this->isConsumablesCategory()) {
            $classes[] = 'owwa-issuances-list--consumables';
        } else {
            $classes[] = 'owwa-issuances-list--property';
        }

        return $classes;
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutTrashed())
                ->excludeQueryWhenResolvingRecord(),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->onlyTrashed())
                ->excludeQueryWhenResolvingRecord(),
        ];
    }

    public function isConsumablesCategory(): bool
    {
        return ItemCategory::query()
            ->whereKey($this->activeItemCategoryId())
            ->first()
            ?->getTemplateSlug() === 'consumables';
    }

    public function content(Schema $schema): Schema
    {
        $actions = [];

        if ($this->isConsumablesCategory()) {
            $actions[] = IssuanceRsmiExportAction::make();
        }

        if ($actions !== []) {
            $actionsComponent = Actions::make($actions);
            /** @var mixed $actionsComponent */
            $actionsComponent = $actionsComponent->alignEnd();

            $tabsAndExports = Flex::make([
                $this->getTabsContentComponent(),
                $actionsComponent,
            ]);
            /** @var mixed $tabsAndExports */
            $tabsAndExports = $tabsAndExports->alignBetween()->verticallyAlignCenter();

            return $schema
                ->components([
                    $tabsAndExports,
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                    EmbeddedTable::make(),
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
                ]);
        }

        $tabsOnly = Flex::make([
            $this->getTabsContentComponent(),
        ]);
        /** @var mixed $tabsOnly */
        $tabsOnly = $tabsOnly->alignStart()->verticallyAlignCenter();

        return $schema
            ->components([
                $tabsOnly,
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
