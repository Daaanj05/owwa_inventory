<?php

namespace App\Filament\Resources\IncidentReports\Pages;

use App\Filament\Concerns\CoaListPageExports;
use App\Filament\Resources\IncidentReports\IncidentReportResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\InventoryUnit;
use App\Services\DisposalStockValidator;
use App\Support\CustodianOfficeScope;
use App\Support\OfficeSignatoryDefaults;
use App\Support\ScanAssetHandoff;
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
use Livewire\Attributes\Url;

class ListIncidentReports extends ListRecords
{
    use CoaListPageExports;

    protected static string $resource = IncidentReportResource::class;

    #[Url]
    public ?int $create = null;

    #[Url]
    public ?int $inventory_unit_id = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $pendingCreateFormData = null;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return 'Incident reports';
    }

    public function getRecord(): mixed
    {
        return null;
    }

    public function mount(): void
    {
        parent::mount();

        $this->mountCreateFromScanQuery();
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
        ];
    }

    public function content(Schema $schema): Schema
    {
        $actionsComponent = Actions::make([
            OwwaFormModalDefaults::createActionForResource(IncidentReportResource::class, OwwaFormModalDefaults::WIDTH_STANDARD)
                ->fillForm(function (): array {
                    $defaults = [
                        'disposal_type' => 'lost_stolen_damaged',
                        'office_id' => CustodianOfficeScope::inventoryOfficeId(),
                        'disposal_date' => now()->toDateString(),
                    ];

                    if ($this->pendingCreateFormData !== null) {
                        $defaults = array_merge($defaults, $this->pendingCreateFormData);
                        $this->pendingCreateFormData = null;
                    }

                    return $defaults;
                })
                ->mutateFormDataUsing(function (array $data): array {
                    $data['disposal_type'] = 'lost_stolen_damaged';
                    app(DisposalStockValidator::class)->validateForCreate($data);

                    return OfficeSignatoryDefaults::mergeNonBlank(
                        OfficeSignatoryDefaults::forDisposal(
                            isset($data['office_id']) ? (int) $data['office_id'] : null,
                        ),
                        $data,
                    );
                }),
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

    protected function mountCreateFromScanQuery(): void
    {
        if ((int) ($this->create ?? 0) !== 1 || ! IncidentReportResource::canCreate()) {
            return;
        }

        $unitId = (int) ($this->inventory_unit_id ?? 0);
        $this->create = null;
        $this->inventory_unit_id = null;

        if ($unitId <= 0) {
            return;
        }

        $unit = InventoryUnit::query()
            ->with(['item.category', 'office', 'issuance', 'acquisition'])
            ->find($unitId);

        $resolved = ScanAssetHandoff::resolveActionableUnit($unit);

        if ($resolved === null || ScanAssetHandoff::isUnitClaimed($resolved['unit'])) {
            Notification::make()
                ->title('Cannot open incident report from scan')
                ->body('That inventory unit is unavailable, already claimed, or outside your office.')
                ->danger()
                ->send();

            return;
        }

        $this->pendingCreateFormData = ScanAssetHandoff::disposalFormDefaults($resolved['unit'], 'lost_stolen_damaged');

        $this->mountAction('create', [], ['schemaComponent' => 'content']);
    }
}
