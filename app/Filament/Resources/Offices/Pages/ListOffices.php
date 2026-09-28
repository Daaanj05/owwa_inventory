<?php

namespace App\Filament\Resources\Offices\Pages;

use App\Filament\Concerns\HasSetupActiveTabToolbar;
use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Offices\OfficeResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Office;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;

class ListOffices extends ListRecords
{
    use HasSetupActiveTabToolbar;
    use HasSystemAdminWizardHeading;

    protected static string $resource = OfficeResource::class;

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
            OwwaFormModalDefaults::createActionForResource(OfficeResource::class, OwwaFormModalDefaults::WIDTH_COMPACT)
                ->label('New Office'),
        ];
    }

    protected function setupToolbarCreateLabel(): ?string
    {
        return 'New Office';
    }

    protected function setupActiveTabArchivedCount(): int
    {
        return (int) Office::query()->whereNotNull('archived_at')->count();
    }

    protected function getTableQuery(): Builder
    {
        return Office::query();
    }
}
