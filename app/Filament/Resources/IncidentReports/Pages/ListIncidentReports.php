<?php

namespace App\Filament\Resources\IncidentReports\Pages;

use App\Filament\Concerns\CoaListPageExports;
use App\Filament\Concerns\HasSetupArchiveView;
use App\Filament\Resources\IncidentReports\IncidentReportResource;
use App\Filament\Resources\IncidentReports\Schemas\IncidentReportForm;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\InventoryUnit;
use App\Services\DisposalStockValidator;
use App\Support\OfficeSignatoryDefaults;
use App\Support\ScanAssetHandoff;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Table;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Livewire\Livewire;

class ListIncidentReports extends ListRecords
{
    use CoaListPageExports;
    use HasSetupArchiveView;

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
        $this->registerSetupArchiveViewHook();
    }

    protected function setupArchiveViewArchivedCount(): int
    {
        return IncidentReportResource::getEloquentQuery()->onlyTrashed()->count();
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->applySetupArchiveQuery($query))
            ->emptyStateHeading(fn (): string => $this->showingArchived
                ? 'No archived incident reports'
                : 'No incident reports recorded')
            ->emptyStateDescription(fn (): string => $this->showingArchived
                ? 'Archived reports will appear here.'
                : 'Lost, stolen, damaged, or destroyed property reports will appear here.');
    }

    public function content(Schema $schema): Schema
    {
        $this->registerIncidentSearchRowActions();

        return $schema
            ->components([
                Actions::make([
                    $this->createIncidentReportAction(),
                ])->extraAttributes(['class' => 'owwa-incident-create-action-source']),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    protected function registerIncidentSearchRowActions(): void
    {
        $requestKey = 'owwa.incident.search_row_actions.'.static::class;
        if (request()->attributes->get($requestKey)) {
            return;
        }

        request()->attributes->set($requestKey, true);

        $scope = static::class;

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            function () use ($scope): HtmlString {
                $livewire = Livewire::current();

                if (! is_object($livewire) || ! is_a($livewire, $scope)) {
                    return new HtmlString('');
                }

                /** @var self $livewire */
                return new HtmlString(
                    (string) view('filament.tables.incident-search-row-actions', [
                        'showCreate' => ! $livewire->showingArchived && IncidentReportResource::canCreate(),
                    ])
                );
            },
            scopes: $scope,
        );
    }

    /**
     * @return array<int, string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'owwa-setup-archive-toggle',
            'owwa-incident-reports-toolbar',
            'owwa-search-row-toolbar',
        ];
    }

    protected function createIncidentReportAction(): CreateAction
    {
        return OwwaFormModalDefaults::createActionForResource(IncidentReportResource::class, OwwaFormModalDefaults::WIDTH_STANDARD)
            ->visible(fn (): bool => ! $this->showingArchived)
            ->fillForm(function (): array {
                $defaults = [
                    'disposal_type' => 'lost_stolen_damaged',
                    'office_id' => IncidentReportForm::defaultOfficeId(),
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
            });
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
