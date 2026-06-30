<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\Pages;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Pages\InventoryCategoryDashboard;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Filament\Resources\PhysicalInventoryPlans\PhysicalInventoryPlanResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\ItemCategory;
use App\Models\PhysicalInventoryPlanLine;
use App\Models\User;
use App\Services\InventoryPlanStartCountService;
use App\Services\InventoryPlanValidator;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
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
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

class ListPhysicalInventoryPlans extends ListRecords
{
    use SyncsActiveItemCategory;

    protected static string $resource = PhysicalInventoryPlanResource::class;

    #[Url]
    public ?int $create = null;

    public function getTitle(): string|Htmlable
    {
        return 'Inventory Schedules';
    }

    public function getHeading(): string|Htmlable
    {
        $categoryName = ItemCategory::query()->whereKey((int) session('active_item_category_id'))->value('name');

        if (! $categoryName) {
            return 'Inventory Schedules';
        }

        return new HtmlString($this->getWizardHeaderBreadcrumb($categoryName, 'Inventory Schedules'));
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

        if ((int) ($this->create ?? 0) !== 1 || ! PhysicalInventoryPlanResource::canCreate()) {
            return;
        }

        $this->create = null;

        $this->mountAction('create', [], ['schemaComponent' => 'content']);
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutTrashed())
                ->excludeQueryWhenResolvingRecord(),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->onlyTrashed()),
            'all' => Tab::make('All')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScopes([SoftDeletingScope::class]))
                ->excludeQueryWhenResolvingRecord(),
        ];
    }

    public function startPlanLineCount(int $lineId): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $line = PhysicalInventoryPlanLine::query()->findOrFail($lineId);

        $session = app(InventoryPlanStartCountService::class)->startCount($line, $user);

        $this->redirect(PhysicalCountSessionResource::getUrl('view', ['record' => $session]));
    }

    public function content(Schema $schema): Schema
    {
        $actionsComponent = Actions::make([
            OwwaFormModalDefaults::createAction(OwwaFormModalDefaults::WIDTH_STANDARD)
                ->label('New Inventory Schedule')
                ->modalHeading('New Inventory Schedule')
                ->createAnother(false)
                ->extraModalWindowAttributes(['class' => OwwaFormModalDefaults::MODAL_WINDOW_CLASS.' owwa-inventory-plan-modal'])
                ->mutateFormDataUsing(function (array $data): array {
                    $categoryId = (int) session('active_item_category_id', 0);
                    if ($categoryId > 0) {
                        $data['item_category_id'] = $categoryId;
                    }

                    return $data;
                })
                ->before(function (CreateAction $action): void {
                    $data = $action->getFormData();

                    app(InventoryPlanValidator::class)->validateForSave(
                        $data,
                        null,
                        $data['lines'] ?? [],
                    );
                })
                ->successRedirectUrl(fn ($record): string => PhysicalInventoryPlanResource::viewModalUrl($record)),
        ]);

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
}
