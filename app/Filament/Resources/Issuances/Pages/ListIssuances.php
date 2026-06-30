<?php

namespace App\Filament\Resources\Issuances\Pages;

use App\Filament\Concerns\CoaListPageExports;
use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Pages\InventoryCategoryDashboard;
use App\Filament\Resources\Issuances\IssuanceResource;
use App\Models\ItemCategory;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

class ListIssuances extends ListRecords
{
    use CoaListPageExports;
    use HasSystemAdminWizardHeading;
    use SyncsActiveItemCategory;

    protected static string $resource = IssuanceResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return 'Issuances';
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        $categoryName = ItemCategory::query()->whereKey((int) session('active_item_category_id'))->value('name');

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
        $categoryId = (int) session('active_item_category_id', 0);
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
            'all' => Tab::make('All')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScopes([SoftDeletingScope::class]))
                ->excludeQueryWhenResolvingRecord(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $categorySlug = ItemCategory::query()
            ->whereKey((int) session('active_item_category_id'))
            ->first()
            ?->getTemplateSlug();

        $selectedExportLabel = match ($categorySlug) {
            'consumables' => 'Export RSMI — selected rows',
            'ppe', 'semi_expendable' => 'Export issuance form — selected rows',
            default => 'Export Report',
        };

        $issuanceSelectionHint = $categorySlug === 'consumables'
            ? 'Use Requisitions → Export RIS for the request slip (Appendix 63), not Issuances. RSMI (Appendix 64) is the daily issue report.'
            : null;

        $actions = [
            $this->coaExportReportAction(
                'coaIssuance',
                'owwa.export.bulk.issuances',
                $selectedExportLabel,
                $issuanceSelectionHint,
            ),
        ];

        if ($categorySlug === 'consumables') {
            $actions[] = Action::make('exportTodaysRsmi')
                ->label('Export today\'s RSMI (Excel)')
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

                    $this->redirect(route('owwa.export.issuances.today-rsmi'));
                });
        }

        $actionsComponent = Actions::make($actions);
        /** @var mixed $actionsComponent */
        $actionsComponent = $actionsComponent->alignEnd();

        $flexComponent = Flex::make([
            $this->getTabsContentComponent(),
            $actionsComponent,
        ]);
        /** @var mixed $flexComponent */
        $flexComponent = $flexComponent->alignBetween()->verticallyAlignCenter();

        return $schema
            ->components([
                $flexComponent,
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
