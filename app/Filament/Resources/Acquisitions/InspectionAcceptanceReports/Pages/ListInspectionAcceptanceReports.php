<?php

namespace App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Acquisitions\Concerns\HasAcquisitionDocumentTabs;
use App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\InspectionAcceptanceReportResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\InspectionAcceptanceReport;
use App\Models\PurchaseOrder;
use App\Services\InspectionAcceptanceReportWorkflowService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
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

class ListInspectionAcceptanceReports extends ListRecords
{
    use HasAcquisitionDocumentTabs;
    use HasSystemAdminWizardHeading;
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

    public function updatedDefaultTableAction(): void
    {
        $this->normalizeTableActionForReadOnly();
    }

    public function updatedDefaultTableActionRecord(): void
    {
        $this->normalizeTableActionForReadOnly();
    }

    protected function normalizeTableActionForReadOnly(): void
    {
        if ($this->defaultTableAction !== 'edit' || blank($this->defaultTableActionRecord)) {
            return;
        }

        $record = InspectionAcceptanceReport::query()->find($this->defaultTableActionRecord);
        if ($record && ! $record->isEditable()) {
            $this->defaultTableAction = 'view';
        }
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

    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }

    public function content(Schema $schema): Schema
    {
        $categoryId = $this->activeItemCategoryId();

        $actionsComponent = Actions::make([
            OwwaFormModalDefaults::apply(
                Action::make('createIar')
                    ->label('Create IAR')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->modalHeading('Choose purchase order')
                    ->modalDescription('Select an offline-approved PO that does not yet have an IAR.')
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
            ),
        ])->alignEnd();

        $flexComponent = Flex::make([
            $this->getTabsContentComponent(),
            $actionsComponent,
        ])->alignBetween()->verticallyAlignCenter();

        $this->registerAcquisitionDocumentTabsBelowSearch('iar');

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
