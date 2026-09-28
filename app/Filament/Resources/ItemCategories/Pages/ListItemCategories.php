<?php

namespace App\Filament\Resources\ItemCategories\Pages;

use App\Filament\Concerns\HasSetupActiveTabToolbar;
use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\ItemCategories\ItemCategoryResource;
use App\Filament\Support\OwwaFormModalDefaults;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;

class ListItemCategories extends ListRecords
{
    use HasSetupActiveTabToolbar;
    use HasSystemAdminWizardHeading;

    protected static string $resource = ItemCategoryResource::class;

    /**
     * Filament schemas sometimes call `getRecord()` even on "list" pages.
     * List pages don't have a selected record, so we return `null`.
     */
    public function getRecord(): mixed
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
            OwwaFormModalDefaults::createActionForResource(ItemCategoryResource::class, OwwaFormModalDefaults::WIDTH_COMPACT)
                ->label('New Item Category'),
        ];
    }

    protected function setupToolbarCreateLabel(): ?string
    {
        return 'New Item Category';
    }

    protected function setupActiveTabArchivedCount(): int
    {
        return (int) ItemCategoryResource::getEloquentQuery()->whereNotNull('archived_at')->count();
    }
}
