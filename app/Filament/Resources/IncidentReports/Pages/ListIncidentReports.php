<?php

namespace App\Filament\Resources\IncidentReports\Pages;

use App\Filament\Concerns\CoaListPageExports;
use App\Filament\Resources\IncidentReports\IncidentReportResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Support\CustodianOfficeScope;
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

class ListIncidentReports extends ListRecords
{
    use CoaListPageExports;

    protected static string $resource = IncidentReportResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return 'Incident reports';
    }

    public function getRecord(): mixed
    {
        return null;
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
        $actionsComponent = Actions::make([
            OwwaFormModalDefaults::createAction(OwwaFormModalDefaults::WIDTH_STANDARD)
                ->fillForm(fn (): array => [
                    'disposal_type' => 'lost_stolen_damaged',
                    'office_id' => CustodianOfficeScope::inventoryOfficeId(),
                    'disposal_date' => now()->toDateString(),
                ]),
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
}
