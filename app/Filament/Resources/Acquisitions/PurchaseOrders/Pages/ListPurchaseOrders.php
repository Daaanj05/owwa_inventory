<?php

namespace App\Filament\Resources\Acquisitions\PurchaseOrders\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\StartsOwwaExportBusy;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Acquisitions\Concerns\AcquisitionProcurementExportAction;
use App\Filament\Resources\Acquisitions\Concerns\HasAcquisitionDocumentTabs;
use App\Filament\Resources\Acquisitions\Concerns\HasAcquisitionListViewToggle;
use App\Filament\Resources\Acquisitions\Concerns\HasAcquisitionSearchRowActions;
use App\Filament\Resources\Acquisitions\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Acquisitions\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Filament\Resources\Pages\ListRecordsWithoutFilterUrl;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\AcquisitionPaperwork;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderWorkflowService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class ListPurchaseOrders extends ListRecordsWithoutFilterUrl
{
    use HasAcquisitionDocumentTabs;
    use HasAcquisitionListViewToggle;
    use HasAcquisitionSearchRowActions;
    use HasSystemAdminWizardHeading;
    use StartsOwwaExportBusy;
    use SyncsActiveItemCategory;

    #[Url]
    public int|string|null $category = null;

    protected static string $resource = PurchaseOrderResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Purchase orders';
    }

    public function getHeading(): string|Htmlable
    {
        return $this->acquisitionWizardHeading('Acquisitions');
    }

    public function mount(): void
    {
        parent::mount();
        $this->syncActiveItemCategoryFromRequest();
        $this->normalizeTableActionForReadOnly();
    }

    protected function acquisitionListToggleMode(): string
    {
        return 'archive';
    }

    protected function acquisitionListToggleBadgeCount(): int
    {
        return PurchaseOrder::query()
            ->whereNotNull('archived_at')
            ->whereHas('purchaseRequest', fn (Builder $q): Builder => $q->where('item_category_id', $this->activeItemCategoryId()))
            ->count();
    }

    protected function normalizeTableActionForReadOnly(): void
    {
        // View-first for issued POs: run from mount() only (not updatedDefaultTableAction*),
        // so visible Edit → mountTableAction('edit') is not flipped back to view.
        // Unsaved drafts keep edit (create / row click edit-first).
        if ($this->defaultTableAction !== 'edit' || blank($this->defaultTableActionRecord)) {
            return;
        }

        $record = PurchaseOrder::query()->find($this->defaultTableActionRecord);
        if ($record && ! $record->isUnsavedPoDraft()) {
            $this->defaultTableAction = 'view';
        }
    }

    public function table(Table $table): Table
    {
        return PurchaseOrdersTable::configure($table)
            ->modifyQueryUsing(function (Builder $query): Builder {
                return $this->applyAcquisitionDateFilter(
                    $this->applyAcquisitionArchiveQuery($query),
                    'po_date',
                );
            });
    }

    public function content(Schema $schema): Schema
    {
        $this->registerAcquisitionSearchRowActions(
            createActionName: 'createPo',
            createLabel: 'Create PO',
            showCreate: fn ($page): bool => ! $page->showingArchived,
        );
        $this->registerAcquisitionDocumentTabsBelowSearch('po');

        return $schema->components([
            Flex::make([
                $this->acquisitionDateRangeHeader(),
            ])->alignBetween()->verticallyAlignCenter(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    public function createPoAction(): Action
    {
        return OwwaFormModalDefaults::apply(
            Action::make('createPo')
                ->label('Create PO')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->visible(fn (): bool => ! $this->showingArchived)
                ->modalHeading('Choose purchase request')
                ->modalDescription('Select an approved PR that does not yet have a purchase order.')
                ->modalSubmitActionLabel('Create PO')
                ->form([
                    Select::make('acquisition_paperwork_id')
                        ->label('Approved PR')
                        ->required()
                        ->searchable()
                        ->allowHtml()
                        ->optionsLimit(200)
                        ->extraAttributes(['class' => 'owwa-doc-picker-select'])
                        ->options(function (): array {
                            return AcquisitionPaperwork::query()
                                ->with(['requestingOffice', 'office', 'lines'])
                                ->where('item_category_id', $this->activeItemCategoryId())
                                ->where('pr_status', AcquisitionPaperwork::STATUS_APPROVED)
                                ->whereNull('archived_at')
                                ->whereDoesntHave('purchaseOrder')
                                ->orderByDesc('pr_completed_at')
                                ->orderByDesc('pr_date')
                                ->get()
                                ->mapWithKeys(fn (AcquisitionPaperwork $pr): array => [
                                    $pr->id => $pr->purchaseOrderPickerOptionHtml(),
                                ])
                                ->all();
                        })
                        ->getOptionLabelUsing(function ($value): ?string {
                            if (blank($value)) {
                                return null;
                            }

                            $pr = AcquisitionPaperwork::query()->find($value);

                            return $pr?->purchaseOrderPickerSummary();
                        }),
                ])
                ->action(function (array $data): void {
                    try {
                        $pr = AcquisitionPaperwork::query()->findOrFail($data['acquisition_paperwork_id']);
                        $po = app(PurchaseOrderWorkflowService::class)->createFromApprovedPr($pr);
                        Notification::make()->title('Purchase order created')->success()->send();
                        $this->redirect(PurchaseOrderResource::viewModalUrl($po));
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Unable to create PO')
                            ->body(collect($exception->errors())->flatten()->first() ?? 'Validation failed.')
                            ->danger()
                            ->send();
                    }
                }),
            OwwaFormModalDefaults::WIDTH_COMPACT,
            'owwa-doc-picker-modal',
        );
    }

    public function exportProcurementReportAction(): Action
    {
        return AcquisitionProcurementExportAction::make('po');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->exportProcurementReportAction(),
            $this->createPoAction(),
        ];
    }
}
