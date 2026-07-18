<?php

namespace App\Filament\Resources\Issuances\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Pages\InventoryCategoryDashboard;
use App\Filament\Resources\Issuances\IssuanceResource;
use App\Models\ItemCategory;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

class ListIssuances extends ListRecords
{
    use HasSystemAdminWizardHeading;
    use SyncsActiveItemCategory;

    #[Url]
    public int|string|null $category = null;

    /**
     * Consumables-only view within the Active tab: all | today_rsmi.
     */
    #[Url]
    public string $issuanceView = 'all';

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

        if (($this->activeTab ?? null) === 'all') {
            $this->activeTab = 'active';
        }

        if ($this->isConsumablesCategory() && ($this->activeTab ?? null) === 'today_rsmi') {
            $this->activeTab = 'active';
            $this->issuanceView = 'today_rsmi';
        }

        if (! in_array($this->issuanceView, ['all', 'today_rsmi'], true)) {
            $this->issuanceView = 'all';
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

    public function setIssuanceView(string $view): void
    {
        if (! in_array($view, ['all', 'today_rsmi'], true)) {
            return;
        }

        $this->issuanceView = $view;

        if (($this->activeTab ?? null) === 'archived') {
            $this->activeTab = 'active';
        }

        $this->resetTable();
    }

    public function getTabs(): array
    {
        if ($this->isConsumablesCategory()) {
            return [
                'active' => Tab::make('Active')
                    ->modifyQueryUsing(function (Builder $query): Builder {
                        $query = $query->withoutTrashed();

                        if ($this->issuanceView === 'today_rsmi') {
                            $query->whereDate('issuance_date', today());
                        }

                        return $query;
                    })
                    ->excludeQueryWhenResolvingRecord(),
                'archived' => Tab::make('Archived')
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->onlyTrashed())
                    ->excludeQueryWhenResolvingRecord(),
            ];
        }

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

    public function todayRsmiBatchCount(): int
    {
        return (int) IssuanceResource::getEloquentQuery()
            ->whereDate('issuance_date', today())
            ->distinct('issuance_batch_id')
            ->count('issuance_batch_id');
    }

    public function content(Schema $schema): Schema
    {
        static $toolbarPillsHookRegistered = false;

        if (! $toolbarPillsHookRegistered) {
            $toolbarPillsHookRegistered = true;

            FilamentView::registerRenderHook(
                TablesRenderHook::TOOLBAR_SEARCH_AFTER,
                function (): HtmlString {
                    $livewire = \Livewire\Livewire::current();

                    if (! $livewire instanceof self || ! $livewire->isConsumablesCategory()) {
                        return new HtmlString('');
                    }

                    return new HtmlString(
                        (string) view('filament.resources.issuances.partials.consumable-toolbar', [
                            'issuanceView' => $livewire->issuanceView,
                            'activeTab' => $livewire->activeTab ?? 'active',
                            'todayBadge' => $livewire->todayRsmiBatchCount(),
                        ])
                    );
                },
                scopes: static::class,
            );
        }

        $categorySlug = ItemCategory::query()
            ->whereKey($this->activeItemCategoryId())
            ->first()
            ?->getTemplateSlug();

        if ($categorySlug === 'consumables') {
            $actions = [
                Action::make('exportTodaysRsmi')
                    ->label('Export Today\'s RSMI')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->action(function (): void {
                        $count = IssuanceResource::getEloquentQuery()
                            ->whereDate('issuance_date', today())
                            ->count();

                        if ($count === 0) {
                            Notification::make()
                                ->title('No consumable issuances recorded today')
                                ->body('Record stock issues from Requisitions → Accept & issue, then export today\'s RSMI.')
                                ->warning()
                                ->send();

                            return;
                        }

                        OwwaExportBusyDispatcher::start(
                            $this,
                            route('owwa.export.issuances.today-rsmi'),
                            'Preparing Excel export…',
                            'Building your OWWA form…',
                        );
                    }),
            ];

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
