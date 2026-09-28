<?php

namespace App\Filament\Resources\ItemAttributeOptions\Pages;

use App\Filament\Concerns\HasSearchRowToolbarActions;
use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\ItemAttributeOptions\ItemAttributeOptionResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\ItemAttributeOption;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Livewire\Livewire;

class ManageItemAttributeOptions extends ManageRecords
{
    use HasSearchRowToolbarActions;
    use HasSystemAdminWizardHeading;

    protected static string $resource = ItemAttributeOptionResource::class;

    #[Url(as: 'classification')]
    public ?string $kind = null;

    protected bool $itemAttributeToolbarRegistered = false;

    public function mount(): void
    {
        parent::mount();

        $this->kind = $this->resolvedKind();
    }

    public function boot(): void
    {
        $this->registerItemAttributeToolbar();
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return array_merge(parent::getPageClasses(), [
            'owwa-item-attribute-list',
            'owwa-search-row-toolbar',
        ]);
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', true)),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', false)),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            OwwaFormModalDefaults::apply(
                CreateAction::make()
                    ->label('New Item Attribute List')
                    ->modalWidth(OwwaFormModalDefaults::WIDTH_COMPACT)
                    ->createAnotherAction(fn ($action) => $action->label('Save & add another'))
                    ->fillForm(fn (): array => [
                        'kind' => $this->resolvedKind(),
                    ]),
                OwwaFormModalDefaults::WIDTH_COMPACT,
            ),
        ];
    }

    public function cacheInteractsWithHeaderActions(): void
    {
        parent::cacheInteractsWithHeaderActions();

        $this->cachedHeaderActions = [];
    }

    /**
     * @return list<array{label: string, action?: string, url?: string, style?: string, schema?: string}>
     */
    protected function searchRowToolbarButtons(): array
    {
        return [
            [
                'label' => 'New Item Attribute List',
                'action' => 'create',
                'style' => 'primary',
            ],
        ];
    }

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()->where('kind', $this->resolvedKind());
    }

    protected function registerItemAttributeToolbar(): void
    {
        if ($this->itemAttributeToolbarRegistered) {
            return;
        }

        $this->itemAttributeToolbarRegistered = true;

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            function (): HtmlString {
                $livewire = Livewire::current();

                if (! $livewire instanceof self) {
                    return new HtmlString('');
                }

                $activeTab = $livewire->activeTab ?? 'active';

                return new HtmlString(
                    (string) view('filament.tables.item-attribute-classification-tabs', [
                        'kind' => $livewire->resolvedKind(),
                        'tabs' => ItemAttributeOption::kindOptions(),
                    ]).
                    (string) view('filament.tables.setup-active-tab-toggle', [
                        'showingArchived' => $activeTab === 'archived',
                        'archivedCount' => ItemAttributeOption::query()
                            ->where('kind', $livewire->resolvedKind())
                            ->where('is_active', false)
                            ->count(),
                    ])
                );
            },
        );
    }

    public function resolvedKind(): string
    {
        $kind = $this->kind;

        if (is_string($kind) && array_key_exists($kind, ItemAttributeOption::kindOptions())) {
            return $kind;
        }

        return ItemAttributeOption::KIND_UNIT;
    }
}
