<?php

namespace App\Filament\Resources\Acquisitions\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Concerns\StartsOwwaExportBusy;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Acquisitions\Concerns\AcquisitionProcurementExportAction;
use App\Filament\Resources\Acquisitions\Concerns\HasAcquisitionDocumentTabs;
use App\Filament\Resources\Acquisitions\Concerns\HasAcquisitionListViewToggle;
use App\Filament\Resources\Acquisitions\Concerns\HasAcquisitionSearchRowActions;
use App\Filament\Resources\Acquisitions\Tables\OpeningBalanceBatchesTable;
use App\Filament\Resources\Acquisitions\Tables\ReceivedAcquisitionsTable;
use App\Filament\Resources\Pages\ListRecordsWithoutFilterUrl;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\StockOpeningBalanceBatch;
use App\Services\StockOpeningBalanceBatchService;
use App\Support\SupplyOfficeResolver;
use Filament\Actions\Action;
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

class ListReceivedAcquisitions extends ListRecordsWithoutFilterUrl
{
    use HasAcquisitionDocumentTabs;
    use HasAcquisitionListViewToggle;
    use HasAcquisitionSearchRowActions;
    use HasSystemAdminWizardHeading;
    use StartsOwwaExportBusy;
    use SyncsActiveItemCategory;

    #[Url]
    public int|string|null $category = null;

    protected static string $resource = AcquisitionResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->showingOpeningBalances ? 'Opening balances' : 'Received acquisitions';
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

    protected function acquisitionListToggleMode(): string
    {
        return 'opening';
    }

    protected function acquisitionListToggleBadgeCount(): int
    {
        return StockOpeningBalanceBatch::query()
            ->when(
                $this->activeItemCategoryId() > 0,
                fn (Builder $q): Builder => $q->where('item_category_id', $this->activeItemCategoryId()),
            )
            ->count();
    }

    public function table(Table $table): Table
    {
        if ($this->showingOpeningBalances) {
            return OpeningBalanceBatchesTable::configure(
                $table->query($this->openingBalanceBatchQuery())
            )->modifyQueryUsing(function (Builder $query): Builder {
                $from = filled($this->filterDateFrom) ? substr((string) $this->filterDateFrom, 0, 10) : null;
                $until = filled($this->filterDateUntil) ? substr((string) $this->filterDateUntil, 0, 10) : null;

                if ($from !== null && $until !== null && $from > $until) {
                    return $query;
                }

                if ($from === null && $until === null) {
                    return $query;
                }

                return $query->where(function (Builder $q) use ($from, $until): void {
                    $q->whereNull('confirmed_at')
                        ->orWhere(function (Builder $confirmed) use ($from, $until): void {
                            $confirmed->whereNotNull('confirmed_at')
                                ->when($from !== null, fn (Builder $inner): Builder => $inner->whereDate('recorded_on', '>=', $from))
                                ->when($until !== null, fn (Builder $inner): Builder => $inner->whereDate('recorded_on', '<=', $until));
                        });
                });
            });
        }

        return ReceivedAcquisitionsTable::configure($table)
            ->modifyQueryUsing(function (Builder $query): Builder {
                return $this->applyAcquisitionDateFilter($query, 'received_at');
            });
    }

    protected function getTableQuery(): Builder
    {
        if ($this->showingOpeningBalances) {
            return $this->openingBalanceBatchQuery();
        }

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

    protected function openingBalanceBatchQuery(): Builder
    {
        return StockOpeningBalanceBatch::query()
            ->with(['office', 'recordedBy'])
            ->withCount('lines')
            ->when(
                $this->activeItemCategoryId() > 0,
                fn (Builder $q): Builder => $q->where('item_category_id', $this->activeItemCategoryId()),
            );
    }

    public function content(Schema $schema): Schema
    {
        $this->registerAcquisitionSearchRowActions(
            createActionName: 'recordOpeningBalance',
            createLabel: 'Record opening balance',
            showCreate: fn ($page): bool => (bool) $page->showingOpeningBalances,
        );
        $this->registerAcquisitionDocumentTabsBelowSearch('received');

        return $schema->components([
            Flex::make([
                $this->acquisitionDateRangeHeader(),
            ])->alignBetween()->verticallyAlignCenter(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    public function exportProcurementReportAction(): Action
    {
        return AcquisitionProcurementExportAction::make('iar');
    }

    public function recordOpeningBalanceAction(): Action
    {
        $categoryId = $this->activeItemCategoryId();

        return OwwaFormModalDefaults::apply(
            Action::make('recordOpeningBalance')
                ->label('Record opening balance')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->modalHeading('Record opening balance')
                ->modalDescription('Save a draft of beginning stock for catalog items already on hand. Confirm later to post stock. This does not create a PO or IAR.')
                ->modalSubmitActionLabel('Save draft')
                ->visible(fn (): bool => $this->showingOpeningBalances)
                ->form(OpeningBalanceBatchesTable::createFormSchema($categoryId))
                ->action(function (array $data): void {
                    $officeId = app(SupplyOfficeResolver::class)->resolve();
                    if ($officeId === null) {
                        Notification::make()
                            ->title('Regional supply office is not configured')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $batch = app(StockOpeningBalanceBatchService::class)->saveDraft(
                            lines: array_values($data['lines'] ?? []),
                            memo: $data['reference'] ?? null,
                            itemCategoryId: $this->activeItemCategoryId() > 0 ? $this->activeItemCategoryId() : null,
                            recordedBy: auth()->user(),
                        );

                        Notification::make()
                            ->title('Opening balance saved as draft')
                            ->body($batch->reference_code.' — confirm to post stock.')
                            ->success()
                            ->send();

                        $this->resetTable();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Unable to save opening balance draft')
                            ->body(collect($exception->errors())->flatten()->first() ?? 'Validation failed.')
                            ->danger()
                            ->send();
                    }
                }),
            OwwaFormModalDefaults::WIDTH_COMPACT,
        );
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->recordOpeningBalanceAction(),
            $this->exportProcurementReportAction(),
        ];
    }
}
