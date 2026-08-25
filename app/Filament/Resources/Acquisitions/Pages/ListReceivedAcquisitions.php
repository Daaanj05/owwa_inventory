<?php

namespace App\Filament\Resources\Acquisitions\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\StartsOwwaExportBusy;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Acquisitions\Concerns\AcquisitionProcurementExportAction;
use App\Filament\Resources\Acquisitions\Concerns\HasAcquisitionDocumentTabs;
use App\Filament\Resources\Acquisitions\Tables\ReceivedAcquisitionsTable;
use App\Filament\Resources\Pages\ListRecordsWithoutFilterUrl;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListReceivedAcquisitions extends ListRecordsWithoutFilterUrl
{
    use HasAcquisitionDocumentTabs;
    use HasSystemAdminWizardHeading;
    use StartsOwwaExportBusy;
    use SyncsActiveItemCategory;

    #[Url]
    public int|string|null $category = null;

    protected static string $resource = AcquisitionResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Received acquisitions';
    }

    public function getHeading(): string|Htmlable
    {
        return $this->acquisitionWizardHeading('Acquisitions');
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function getRecord(): mixed
    {
        return null;
    }

    public function mount(): void
    {
        parent::mount();

        $this->syncActiveItemCategoryFromRequest();
    }

    public function table(Table $table): Table
    {
        return ReceivedAcquisitionsTable::configure($table);
    }

    protected function getTableQuery(): Builder
    {
        return AcquisitionResource::getEloquentQuery()
            ->whereNull('archived_at')
            ->where(function (Builder $query): void {
                $query->whereNotNull('received_at')
                    ->orWhereHas(
                        'purchaseOrder.inspectionAcceptanceReport',
                        fn (Builder $iar): Builder => $iar->whereNotNull('stock_received_at'),
                    );
            })
            ->with([
                'office',
                'purchaseOrder.inspectionAcceptanceReport',
            ]);
    }

    public function content(Schema $schema): Schema
    {
        $this->registerAcquisitionDocumentTabsBelowSearch('received');

        $actionsComponent = Actions::make([
            AcquisitionProcurementExportAction::make('pr'),
        ])->alignEnd();

        $flexComponent = Flex::make([
            $actionsComponent,
        ])->alignEnd();

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
