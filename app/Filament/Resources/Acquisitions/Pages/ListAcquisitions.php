<?php

namespace App\Filament\Resources\Acquisitions\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\StartsOwwaExportBusy;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Acquisitions\Concerns\AcquisitionProcurementExportAction;
use App\Filament\Resources\Acquisitions\Concerns\HasAcquisitionDocumentTabs;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\AcquisitionPaperwork;
use App\Models\Requisition;
use App\Models\User;
use App\Services\RequisitionPurchaseRequestService;
use App\Support\CustodianOfficeScope;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
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
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class ListAcquisitions extends ListRecords
{
    use HasAcquisitionDocumentTabs;
    use HasSystemAdminWizardHeading;
    use StartsOwwaExportBusy;
    use SyncsActiveItemCategory;

    #[Url]
    public int|string|null $category = null;

    #[Url(as: 'create_from_requisition')]
    public ?int $createFromRequisitionId = null;

    protected static string $resource = AcquisitionResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Purchase requests';
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
        $this->normalizeTableActionForReadOnlyPaperwork();

        $user = auth()->user();
        if ($this->createFromRequisitionId !== null && $user instanceof User && $user->isSupplyCustodian()) {
            $this->replaceMountedAction('create', [
                'sourceRequisitionId' => $this->createFromRequisitionId,
                'sourceCategoryId' => $this->activeItemCategoryId(),
            ], ['schemaComponent' => 'content']);
        }
    }

    public function updatedDefaultTableAction(): void
    {
        $this->normalizeTableActionForReadOnlyPaperwork();
    }

    public function updatedDefaultTableActionRecord(): void
    {
        $this->normalizeTableActionForReadOnlyPaperwork();
    }

    protected function normalizeTableActionForReadOnlyPaperwork(): void
    {
        if ($this->defaultTableAction !== 'edit' || blank($this->defaultTableActionRecord)) {
            return;
        }

        $paperwork = AcquisitionPaperwork::query()->find($this->defaultTableActionRecord);

        if ($paperwork && (! $paperwork->isPrEditable() || $paperwork->isPrPendingApproval() || $paperwork->isArchived() || $paperwork->isReceived())) {
            $this->defaultTableAction = 'view';
        }
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
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
        $actionsComponent = Actions::make([
            AcquisitionProcurementExportAction::make('pr'),
            OwwaFormModalDefaults::createActionForResource(AcquisitionResource::class, OwwaFormModalDefaults::WIDTH_WIDE)
                ->label('New PR')
                ->mountUsing(function (CreateAction $action, ?Schema $schema): void {
                    $sourceRequisitionId = (int) ($action->getArguments()['sourceRequisitionId'] ?? 0);
                    $sourceCategoryId = (int) ($action->getArguments()['sourceCategoryId'] ?? 0);

                    if ($sourceRequisitionId <= 0 || $sourceCategoryId <= 0) {
                        $schema?->fill();

                        return;
                    }

                    $requisition = Requisition::query()->findOrFail($sourceRequisitionId);
                    $schema?->fill(app(RequisitionPurchaseRequestService::class)->prefillState(
                        $requisition,
                        $sourceCategoryId,
                    ));
                })
                ->before(function (CreateAction $action, Schema $schema): void {
                    $sourceRequisitionId = (int) ($action->getArguments()['sourceRequisitionId'] ?? 0);
                    $sourceCategoryId = (int) ($action->getArguments()['sourceCategoryId'] ?? 0);

                    if ($sourceRequisitionId <= 0 || $sourceCategoryId <= 0) {
                        return;
                    }

                    $requisition = Requisition::query()->findOrFail($sourceRequisitionId);
                    app(RequisitionPurchaseRequestService::class)->validateShortcutLines(
                        $requisition,
                        $sourceCategoryId,
                        array_values($schema->getRawState()['lines'] ?? []),
                    );
                })
                ->mutateFormDataUsing(function (array $data): array {
                    $categoryId = $this->activeItemCategoryId();
                    if ($categoryId > 0) {
                        $data['item_category_id'] = $categoryId;
                    }

                    $data['phase'] = AcquisitionPaperwork::PHASE_PR;
                    $data['pr_status'] = AcquisitionPaperwork::STATUS_DRAFT;
                    $data['po_status'] = AcquisitionPaperwork::STATUS_DRAFT;
                    $data['iar_status'] = AcquisitionPaperwork::STATUS_DRAFT;
                    $data['pr_date'] ??= now()->toDateString();
                    $data['recorded_by'] = auth()->id();
                    $regionalOfficeId = app(\App\Support\SupplyOfficeResolver::class)->resolve();
                    $data['office_id'] = $regionalOfficeId ?? CustodianOfficeScope::inventoryOfficeId();
                    $data['requesting_office_id'] = $regionalOfficeId ?? $data['office_id'];

                    return $data;
                })
                ->after(function (AcquisitionPaperwork $record, CreateAction $action): void {
                    $sourceRequisitionId = (int) ($action->getArguments()['sourceRequisitionId'] ?? 0);
                    $sourceCategoryId = (int) ($action->getArguments()['sourceCategoryId'] ?? 0);

                    try {
                        $service = app(RequisitionPurchaseRequestService::class);

                        if ($sourceRequisitionId > 0 && $sourceCategoryId > 0) {
                            $requisition = Requisition::query()->findOrFail($sourceRequisitionId);
                            $service->linkShortcutSources(
                                $record,
                                $requisition,
                                $sourceCategoryId,
                            );

                            return;
                        }

                        $service->linkSelectedSources(
                            $record,
                            $record->requisitions()->pluck('requisitions.id')->all(),
                        );
                    } catch (ValidationException $exception) {
                        $record->delete();

                        throw $exception;
                    }
                })
                ->successRedirectUrl(fn (AcquisitionPaperwork $record): string => AcquisitionResource::viewModalUrl($record)),
            Action::make('archiveSelectedHint')
                ->label('Archive tip')
                ->visible(false),
        ]);

        /** @var mixed $actionsComponent */
        $actionsComponent = $actionsComponent->alignEnd();

        $flexComponent = Flex::make([
            $this->getTabsContentComponent(),
            $actionsComponent,
        ]);

        /** @var mixed $flexComponent */
        $flexComponent = $flexComponent->alignBetween()->verticallyAlignCenter();

        $this->registerAcquisitionDocumentTabsBelowSearch('pr');

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
