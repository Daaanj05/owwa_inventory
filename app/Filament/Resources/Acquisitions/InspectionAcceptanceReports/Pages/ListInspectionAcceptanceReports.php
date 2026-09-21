<?php

namespace App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\StartsOwwaExportBusy;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Acquisitions\Concerns\AcquisitionProcurementExportAction;
use App\Filament\Resources\Acquisitions\Concerns\HasAcquisitionDocumentTabs;
use App\Filament\Resources\Acquisitions\Concerns\HasAcquisitionListViewToggle;
use App\Filament\Resources\Acquisitions\Concerns\HasAcquisitionSearchRowActions;
use App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\InspectionAcceptanceReportResource;
use App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Tables\InspectionAcceptanceReportsTable;
use App\Filament\Resources\Pages\ListRecordsWithoutFilterUrl;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\InspectionAcceptanceReport;
use App\Models\PurchaseOrder;
use App\Services\InspectionAcceptanceReportWorkflowService;
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

class ListInspectionAcceptanceReports extends ListRecordsWithoutFilterUrl
{
    use HasAcquisitionDocumentTabs;
    use HasAcquisitionListViewToggle;
    use HasAcquisitionSearchRowActions;
    use HasSystemAdminWizardHeading;
    use StartsOwwaExportBusy;
    use SyncsActiveItemCategory;

    #[Url]
    public int|string|null $category = null;

    protected static string $resource = InspectionAcceptanceReportResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Inspection & acceptance';
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
        return InspectionAcceptanceReport::query()
            ->whereNotNull('archived_at')
            ->whereHas('purchaseOrder.purchaseRequest', fn (Builder $q): Builder => $q->where('item_category_id', $this->activeItemCategoryId()))
            ->count();
    }

    protected function normalizeTableActionForReadOnly(): void
    {
        // View-first for issued IARs: run from mount() only (not updatedDefaultTableAction*),
        // so visible Edit → mountTableAction('edit') is not flipped back to view.
        // Unsaved drafts keep edit (create / row click edit-first).
        if ($this->defaultTableAction !== 'edit' || blank($this->defaultTableActionRecord)) {
            return;
        }

        $record = InspectionAcceptanceReport::query()->find($this->defaultTableActionRecord);
        if ($record && ! $record->isUnsavedIarDraft()) {
            $this->defaultTableAction = 'view';
        }
    }

    public function table(Table $table): Table
    {
        return InspectionAcceptanceReportsTable::configure($table)
            ->modifyQueryUsing(function (Builder $query): Builder {
                return $this->applyAcquisitionDateFilter(
                    $this->applyAcquisitionArchiveQuery($query),
                    'iar_date',
                );
            });
    }

    public function content(Schema $schema): Schema
    {
        $this->registerAcquisitionSearchRowActions(
            createActionName: 'createIar',
            createLabel: 'Create IAR',
            showCreate: fn ($page): bool => ! $page->showingArchived,
        );
        $this->registerAcquisitionDocumentTabsBelowSearch('iar');

        return $schema->components([
            Flex::make([
                $this->acquisitionDateRangeHeader(),
            ])->alignBetween()->verticallyAlignCenter(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    public function createIarAction(): Action
    {
        $categoryId = $this->activeItemCategoryId();

        return OwwaFormModalDefaults::apply(
            Action::make('createIar')
                ->label('Create IAR')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->visible(fn (): bool => ! $this->showingArchived)
                ->modalHeading('Choose purchase order')
                ->modalDescription('Select an approved PO that does not yet have an IAR.')
                ->modalSubmitActionLabel('Create IAR')
                ->form([
                    Select::make('purchase_order_id')
                        ->label('Approved PO')
                        ->required()
                        ->searchable()
                        ->allowHtml()
                        ->optionsLimit(200)
                        ->extraAttributes(['class' => 'owwa-doc-picker-select'])
                        ->options(function () use ($categoryId): array {
                            return PurchaseOrder::query()
                                ->with(['purchaseRequest', 'lines'])
                                ->where('status', PurchaseOrder::STATUS_APPROVED)
                                ->whereNull('archived_at')
                                ->whereDoesntHave('inspectionAcceptanceReport')
                                ->whereHas('purchaseRequest', fn (Builder $query) => $query->where('item_category_id', $categoryId))
                                ->orderByDesc('approved_at')
                                ->get()
                                ->mapWithKeys(fn (PurchaseOrder $po): array => [
                                    $po->id => $po->inspectionAcceptancePickerOptionHtml(),
                                ])
                                ->all();
                        })
                        ->getOptionLabelUsing(function ($value): ?string {
                            if (blank($value)) {
                                return null;
                            }

                            $po = PurchaseOrder::query()->with('purchaseRequest')->find($value);

                            return $po?->inspectionAcceptancePickerSummary();
                        }),
                ])
                ->action(function (array $data): void {
                    try {
                        $po = PurchaseOrder::query()->findOrFail($data['purchase_order_id']);
                        $iar = app(InspectionAcceptanceReportWorkflowService::class)->createFromApprovedPo($po);
                        Notification::make()->title('IAR created')->success()->send();
                        $this->redirect(InspectionAcceptanceReportResource::viewModalUrl($iar));
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Unable to create IAR')
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
        return AcquisitionProcurementExportAction::make('iar');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->exportProcurementReportAction(),
            $this->createIarAction(),
        ];
    }
}
