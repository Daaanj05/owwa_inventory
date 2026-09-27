<?php

namespace App\Filament\Resources\Issuances\Pages;

use App\Filament\Concerns\HasSearchRowToolbarActions;
use App\Filament\Concerns\HasSetupArchiveView;
use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\StartsOwwaExportBusy;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Issuances\Concerns\IssuanceRsmiExportAction;
use App\Filament\Resources\Issuances\IssuanceResource;
use App\Filament\Resources\Pages\ListRecordsWithoutFilterUrl;
use App\Models\ItemCategory;
use App\Support\CategoryWizardBreadcrumb;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListIssuances extends ListRecordsWithoutFilterUrl
{
    use HasSearchRowToolbarActions;
    use HasSetupArchiveView;
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

        return CategoryWizardBreadcrumb::make($categoryName, 'Issuances', $this->activeItemCategoryId());
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

    public function mount(): void
    {
        parent::mount();

        $this->syncActiveItemCategoryFromRequest();
        $this->registerSetupArchiveViewHook();
    }

    protected function setupArchiveViewArchivedCount(): int
    {
        return IssuanceResource::getEloquentQuery()->onlyTrashed()->count();
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

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        $classes = parent::getPageClasses();
        $classes[] = 'owwa-setup-archive-toggle';
        $classes[] = 'owwa-search-row-toolbar';
        $classes[] = 'owwa-issuances-list';

        if ($this->isConsumablesCategory()) {
            $classes[] = 'owwa-issuances-list--consumables';
        } else {
            $classes[] = 'owwa-issuances-list--property';
        }

        return $classes;
    }

    public function isConsumablesCategory(): bool
    {
        return ItemCategory::query()
            ->whereKey($this->activeItemCategoryId())
            ->first()
            ?->getTemplateSlug() === 'consumables';
    }

    /**
     * @return list<array{label: string, action?: string, url?: string, style?: string}>
     */
    protected function searchRowToolbarButtons(): array
    {
        if (! $this->isConsumablesCategory()) {
            return [];
        }

        return [
            [
                'label' => 'Export Report',
                'action' => 'exportRsmiReport',
                'style' => 'gray',
            ],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            IssuanceRsmiExportAction::make()
                ->visible(fn (): bool => $this->isConsumablesCategory()),
        ];
    }
}
