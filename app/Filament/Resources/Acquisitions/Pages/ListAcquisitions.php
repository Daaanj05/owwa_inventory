<?php

namespace App\Filament\Resources\Acquisitions\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Pages\InventoryCategoryDashboard;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Acquisitions\Paperwork\AcquisitionPaperworkResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\AcquisitionPaperwork;
use App\Models\ItemCategory;
use App\Support\CustodianOfficeScope;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListAcquisitions extends ListRecords
{
    use HasSystemAdminWizardHeading;
    use SyncsActiveItemCategory;

    protected static string $resource = AcquisitionResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Acquisitions';
    }

    public function getHeading(): string|Htmlable
    {
        $categoryName = ItemCategory::query()->whereKey((int) session('active_item_category_id'))->value('name');

        if (! $categoryName) {
            return 'Acquisitions';
        }

        return new HtmlString($this->getWizardHeaderBreadcrumb($categoryName, 'Acquisitions'));
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    /**
     * Filament schemas sometimes call `getRecord()` even on "list" pages.
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

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')
                ->excludeQueryWhenResolvingRecord(),
            'in_progress' => Tab::make('In progress')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('received_at'))
                ->excludeQueryWhenResolvingRecord(),
            'received' => Tab::make('Received')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('received_at'))
                ->excludeQueryWhenResolvingRecord(),
        ];
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

    public function content(Schema $schema): Schema
    {
        $actionsComponent = Actions::make([
            OwwaFormModalDefaults::createAction(OwwaFormModalDefaults::WIDTH_WIDE)
                ->label('New acquisition')
                ->mutateFormDataUsing(function (array $data): array {
                    $categoryId = (int) session('active_item_category_id', 0);
                    if ($categoryId > 0) {
                        $data['item_category_id'] = $categoryId;
                    }

                    $data['phase'] = AcquisitionPaperwork::PHASE_PR;
                    $data['pr_status'] = AcquisitionPaperwork::STATUS_DRAFT;
                    $data['po_status'] = AcquisitionPaperwork::STATUS_DRAFT;
                    $data['iar_status'] = AcquisitionPaperwork::STATUS_DRAFT;
                    $data['pr_date'] ??= now()->toDateString();
                    $data['office_id'] ??= CustodianOfficeScope::inventoryOfficeId();

                    return $data;
                })
                ->successRedirectUrl(fn (AcquisitionPaperwork $record): string => AcquisitionPaperworkResource::viewModalUrl($record)),
        ]);

        /** @var mixed $actionsComponent */
        $actionsComponent = $actionsComponent->alignEnd();

        $flexComponent = Flex::make([
            $this->getTabsContentComponent(),
            $actionsComponent,
        ]);

        /** @var mixed $flexComponent */
        $flexComponent = $flexComponent->alignBetween()->verticallyAlignCenter();

        return $schema->components([
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
