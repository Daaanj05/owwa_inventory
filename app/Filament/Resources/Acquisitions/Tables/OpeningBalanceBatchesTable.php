<?php

namespace App\Filament\Resources\Acquisitions\Tables;

use App\Filament\Resources\Items\Support\ItemOpeningStockFields;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaTableDefaults;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\StockOpeningBalanceBatch;
use App\Services\StockOpeningBalanceBatchService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Throwable;

class OpeningBalanceBatchesTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->extraAttributes(['class' => 'owwa-acquisition-docs-table'])
            ->columns([
                TextColumn::make('reference_code')
                    ->label('Reference No.')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (StockOpeningBalanceBatch $record): string => $record->isConfirmed() ? 'Confirmed' : 'Draft')
                    ->color(fn (string $state): string => $state === 'Confirmed' ? 'success' : 'warning'),
                TextColumn::make('recorded_on')
                    ->label('Date')
                    ->date('M d, Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('office.name')
                    ->label('Office')
                    ->sortable(),
                TextColumn::make('lines_count')
                    ->counts('lines')
                    ->label('Lines'),
                TextColumn::make('recordedBy.name')
                    ->label('Recorded by')
                    ->placeholder('—'),
                TextColumn::make('recorded_at')
                    ->label('Saved')
                    ->dateTime('M d, Y g:i A')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No opening balances yet')
            ->emptyStateDescription('Save a draft opening balance, then confirm it to post stock.')
            ->emptyStateIcon('heroicon-o-archive-box')
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (StockOpeningBalanceBatch $record): string => 'Opening balance '.$record->reference_code)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->extraModalFooterActions(fn (Action $action): array => [
                        Action::make('confirmFromView')
                            ->label('Confirm')
                            ->color('success')
                            ->icon(Heroicon::CheckCircle)
                            ->requiresConfirmation()
                            ->modalHeading('Confirm opening balance?')
                            ->modalDescription('This posts the line quantities to stock and locks the batch.')
                            ->modalSubmitActionLabel('Confirm')
                            ->visible(fn (): bool => ($action->getRecord() instanceof StockOpeningBalanceBatch)
                                && $action->getRecord()->isDraft())
                            ->action(function () use ($action): void {
                                $record = $action->getRecord();
                                if (! $record instanceof StockOpeningBalanceBatch) {
                                    return;
                                }
                                self::confirmBatch($record);
                                $action->getLivewire()->resetTable();
                            }),
                    ])
                    ->infolist(fn (StockOpeningBalanceBatch $record): array => [
                        Section::make('Batch')
                            ->schema([
                                TextEntry::make('reference_code')->label('Reference No.'),
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->state(fn (StockOpeningBalanceBatch $record): string => $record->isConfirmed() ? 'Confirmed' : 'Draft')
                                    ->badge()
                                    ->color(fn (StockOpeningBalanceBatch $record): string => $record->isConfirmed() ? 'success' : 'warning'),
                                TextEntry::make('recorded_on')
                                    ->label('Date')
                                    ->date('M d, Y')
                                    ->visible(fn (StockOpeningBalanceBatch $record): bool => $record->isConfirmed()),
                                TextEntry::make('reference')->label('Reference')->placeholder('—'),
                                TextEntry::make('office.name')->label('Office'),
                                TextEntry::make('recordedBy.name')->label('Recorded by')->placeholder('—'),
                            ])
                            ->columns(2),
                        Section::make('Lines')
                            ->schema([
                                RepeatableEntry::make('lines')
                                    ->hiddenLabel()
                                    ->schema([
                                        TextEntry::make('item.name')->label('Item'),
                                        TextEntry::make('quantity')->label('Qty'),
                                        TextEntry::make('unit_cost')->label('Unit cost')->money('PHP'),
                                    ])
                                    ->columns(3),
                            ]),
                    ]),
                self::editDraftAction(),
                Action::make('confirm')
                    ->label('Confirm')
                    ->icon(Heroicon::CheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Confirm opening balance?')
                    ->modalDescription('This posts the line quantities to stock and locks the batch.')
                    ->modalSubmitActionLabel('Confirm')
                    ->visible(fn (StockOpeningBalanceBatch $record): bool => $record->isDraft())
                    ->action(function (StockOpeningBalanceBatch $record): void {
                        self::confirmBatch($record);
                    }),
                DeleteAction::make()
                    ->visible(fn (StockOpeningBalanceBatch $record): bool => $record->isDraft())
                    ->using(function (StockOpeningBalanceBatch $record): void {
                        app(StockOpeningBalanceBatchService::class)->deleteDraft($record);
                    }),
            ])
            ->recordActionsAlignment('end')
            ->recordAction(null)
            ->recordUrl(null);

        return OwwaTableDefaults::hideRedundantToolbarIcons($table);
    }

    protected static function editDraftAction(): Action
    {
        return OwwaFormModalDefaults::apply(
            Action::make('edit')
                ->label('Edit')
                ->icon(Heroicon::PencilSquare)
                ->color('gray')
                ->visible(fn (StockOpeningBalanceBatch $record): bool => $record->isDraft())
                ->fillForm(fn (StockOpeningBalanceBatch $record): array => [
                    'reference' => $record->reference,
                    'lines' => $record->lines()
                        ->get()
                        ->map(fn ($line): array => [
                            'item_id' => $line->item_id,
                            'quantity' => $line->quantity,
                            'unit_cost' => $line->unit_cost,
                        ])
                        ->all(),
                ])
                ->form(fn (StockOpeningBalanceBatch $record): array => [
                    Placeholder::make('reference_code_display')
                        ->label('Reference No.')
                        ->content(fn (): string => (string) $record->reference_code),
                    TextInput::make('reference')
                        ->label('Reference')
                        ->maxLength(255)
                        ->placeholder('e.g. physical count sheet #'),
                    self::linesRepeater(
                        categoryId: (int) ($record->item_category_id ?? 0),
                        includeItemIds: $record->lines()->pluck('item_id')->map(fn ($id): int => (int) $id)->all(),
                    ),
                ])
                ->action(function (array $data, StockOpeningBalanceBatch $record): void {
                    try {
                        app(StockOpeningBalanceBatchService::class)->updateDraft(
                            batch: $record,
                            lines: array_values($data['lines'] ?? []),
                            memo: $data['reference'] ?? null,
                            recordedBy: auth()->user(),
                        );

                        Notification::make()
                            ->title('Draft updated')
                            ->success()
                            ->send();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Unable to update draft')
                            ->body(collect($exception->errors())->flatten()->first() ?? 'Validation failed.')
                            ->danger()
                            ->send();
                    }
                }),
            OwwaFormModalDefaults::WIDTH_COMPACT,
        );
    }

    /**
     * @param  list<int>  $includeItemIds
     */
    public static function linesRepeater(int $categoryId, array $includeItemIds = []): Repeater
    {
        $requiresUnitCost = self::categoryRequiresUnitCost($categoryId);
        $unitCostColumn = TableColumn::make('Unit cost')->width('8rem');
        if ($requiresUnitCost) {
            $unitCostColumn = $unitCostColumn->markAsRequired();
        }

        return Repeater::make('lines')
            ->label('Items')
            ->minItems(1)
            ->defaultItems(1)
            ->addActionLabel('Add item')
            ->reorderable(false)
            ->table([
                TableColumn::make('Catalog item')->markAsRequired(),
                TableColumn::make('Quantity')->markAsRequired()->width('7rem'),
                $unitCostColumn,
            ])
            ->schema([
                Select::make('item_id')
                    ->hiddenLabel()
                    ->required()
                    ->searchable()
                    ->options(fn (): array => self::eligibleItemOptions($categoryId, $includeItemIds)),
                TextInput::make('quantity')
                    ->hiddenLabel()
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->integer(),
                TextInput::make('unit_cost')
                    ->hiddenLabel()
                    ->numeric()
                    ->prefix('₱')
                    ->minValue(0)
                    ->required($requiresUnitCost),
            ])
            ->columnSpanFull();
    }

    public static function createFormSchema(int $categoryId): array
    {
        return [
            TextInput::make('reference')
                ->label('Reference')
                ->maxLength(255)
                ->placeholder('e.g. physical count sheet #'),
            self::linesRepeater($categoryId),
        ];
    }

    /**
     * @param  list<int>  $includeItemIds
     * @return array<int|string, string>
     */
    public static function eligibleItemOptions(int $categoryId, array $includeItemIds = []): array
    {
        $officeId = ItemOpeningStockFields::resolveRegionalOfficeId();
        $includeItemIds = array_values(array_unique(array_filter($includeItemIds)));

        return Item::query()
            ->where('item_category_id', $categoryId)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->filter(function (Item $item) use ($officeId, $includeItemIds): bool {
                if (in_array((int) $item->id, $includeItemIds, true)) {
                    return true;
                }

                return ItemOpeningStockFields::canSetStartingStock($item, $officeId);
            })
            ->pluck('name', 'id')
            ->all();
    }

    protected static function categoryRequiresUnitCost(int $categoryId): bool
    {
        if ($categoryId < 1) {
            return false;
        }

        $slug = ItemCategory::query()->find($categoryId)?->getTemplateSlug();

        return in_array($slug, ['ppe', 'semi_expendable'], true);
    }

    protected static function confirmBatch(StockOpeningBalanceBatch $record): void
    {
        try {
            $batch = app(StockOpeningBalanceBatchService::class)->confirm($record);
            Notification::make()
                ->title('Opening balance confirmed')
                ->body($batch->lines_count.' line(s) posted to stock.')
                ->success()
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Unable to confirm opening balance')
                ->body(collect($exception->errors())->flatten()->first() ?? 'Validation failed.')
                ->danger()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Unable to confirm opening balance')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
