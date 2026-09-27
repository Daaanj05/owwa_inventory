<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\Pages;

use App\Filament\Concerns\HasSearchRowToolbarActions;
use App\Filament\Concerns\HasSetupArchiveView;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Filament\Resources\PhysicalInventoryPlans\PhysicalInventoryPlanResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\ItemCategory;
use App\Models\PhysicalInventoryPlanLine;
use App\Models\User;
use App\Services\InventoryPlanStartCountService;
use App\Services\InventoryPlanValidator;
use App\Support\CategoryWizardBreadcrumb;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListPhysicalInventoryPlans extends ListRecords
{
    use HasSearchRowToolbarActions;
    use HasSetupArchiveView;
    use SyncsActiveItemCategory;

    protected static string $resource = PhysicalInventoryPlanResource::class;

    #[Url]
    public int|string|null $category = null;

    #[Url]
    public ?int $create = null;

    public function getTitle(): string|Htmlable
    {
        return 'Inventory Schedules';
    }

    public function getHeading(): string|Htmlable
    {
        $categoryName = ItemCategory::query()->whereKey($this->activeItemCategoryId())->value('name');

        if (! $categoryName) {
            return 'Inventory Schedules';
        }

        return CategoryWizardBreadcrumb::make($categoryName, 'Inventory Schedules', $this->activeItemCategoryId());
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
        $this->registerSetupArchiveViewHook();

        if ((int) ($this->create ?? 0) !== 1 || ! PhysicalInventoryPlanResource::canCreate()) {
            return;
        }

        $this->create = null;

        $this->cacheInteractsWithHeaderActions();
        $this->mountAction('create');
    }

    protected function setupArchiveViewArchivedCount(): int
    {
        return PhysicalInventoryPlanResource::getEloquentQuery()->onlyTrashed()->count();
    }

    protected function applySetupArchiveQuery(Builder $query): Builder
    {
        return $this->showingArchived
            ? $query->onlyTrashed()
            : $query->withoutTrashed();
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->applySetupArchiveQuery($query));
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

    /**
     * @return list<array{label: string, action?: string, url?: string, style?: string}>
     */
    protected function searchRowToolbarButtons(): array
    {
        if ($this->showingArchived) {
            return [];
        }

        return [
            [
                'label' => 'New Inventory Schedule',
                'action' => 'create',
                'style' => 'primary',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'owwa-setup-archive-toggle',
            'owwa-search-row-toolbar',
        ];
    }

    public function cacheInteractsWithHeaderActions(): void
    {
        $this->cachedHeaderActions = [];

        parent::cacheInteractsWithHeaderActions();
    }

    protected function getHeaderActions(): array
    {
        return [
            OwwaFormModalDefaults::createAction(OwwaFormModalDefaults::WIDTH_STANDARD)
                ->label('New Inventory Schedule')
                ->modalHeading('New Inventory Schedule')
                ->createAnother(false)
                ->extraModalWindowAttributes(['class' => OwwaFormModalDefaults::MODAL_WINDOW_CLASS.' owwa-inventory-plan-modal'])
                ->mutateFormDataUsing(function (array $data): array {
                    $categoryId = $this->activeItemCategoryId();
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
                ->successRedirectUrl(fn ($record): string => PhysicalInventoryPlanResource::viewModalUrl($record))
                ->visible(fn (): bool => ! $this->showingArchived),
        ];
    }
}
