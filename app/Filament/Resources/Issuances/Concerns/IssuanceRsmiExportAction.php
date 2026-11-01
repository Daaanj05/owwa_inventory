<?php

namespace App\Filament\Resources\Issuances\Concerns;

use App\Filament\Concerns\StartsOwwaExportBusy;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Issuances\Pages\ListIssuances;
use App\Services\StockLevelExportService;
use App\Support\OwwaExportBusyDispatcher;
use App\Support\StockCardLedgerDateRange;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Support\HtmlString;
use Livewire\Component as LivewireComponent;

/**
 * Consumables RSMI date-range export (Official Excel / Fast PDF).
 */
final class IssuanceRsmiExportAction
{
    public static function make(): Action
    {
        $fiscal = StockCardLedgerDateRange::fiscalYearDefaults();

        return Action::make('exportRsmiReport')
            ->label('Export Report')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->modalHeading('Export options')
            ->modalDescription('RSMI lists consumable issuances for the period—one line per item issued. Supply fills those lines; the accounting recapitulation block is left blank for Accounting.')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Export')
            ->modalCancelActionLabel('Close')
            ->mountUsing(function (ListIssuances $livewire): void {
                $livewire->rsmiExportCount = null;
                $livewire->rsmiExportCountReady = false;
                $livewire->rsmiExportSizeStamp = '0';
                $livewire->scheduleDeferredRsmiExportSize();
            })
            ->fillForm(function (ListIssuances $livewire) use ($fiscal): array {
                return [
                    'export_format' => 'xlsx',
                    'export_scope' => self::defaultExportScope($livewire),
                    'date_from' => $fiscal['date_from'],
                    'date_to' => $fiscal['date_to'],
                    'export_size_stamp' => '0',
                ];
            })
            ->form([
                Grid::make(2)
                    ->schema([
                        Select::make('export_format')
                            ->label('Format')
                            ->options([
                                'xlsx' => 'Excel',
                                'pdf' => 'PDF',
                            ])
                            ->default('xlsx')
                            ->required()
                            ->selectablePlaceholder(false)
                            ->live()
                            ->afterStateUpdated(function (ListIssuances $livewire): void {
                                $livewire->scheduleDeferredRsmiExportSize();
                            })
                            ->columnSpanFull(),
                        Placeholder::make('selection_hint')
                            ->label('')
                            ->content(function (Get $get, ListIssuances $livewire): HtmlString {
                                $get('export_size_stamp');
                                $format = (string) ($get('export_format') ?? 'xlsx');
                                $scope = (string) ($get('export_scope') ?? self::defaultExportScope($livewire));
                                $lines = self::sizeHintLines($livewire, $format, $scope);

                                return new HtmlString(implode('<br><br>', array_map(
                                    fn (string $line): string => e($line),
                                    $lines,
                                )));
                            })
                            ->columnSpanFull(),
                        Radio::make('export_scope')
                            ->label('Rows to export')
                            ->options([
                                'selected' => 'Selected rows only',
                                'all' => 'All issuances in date range',
                            ])
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (ListIssuances $livewire): void {
                                $livewire->scheduleDeferredRsmiExportSize();
                            })
                            ->columnSpanFull(),
                        DatePicker::make('date_from')
                            ->label('From')
                            ->default($fiscal['date_from'])
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (ListIssuances $livewire): void {
                                $livewire->scheduleDeferredRsmiExportSize();
                            }),
                        DatePicker::make('date_to')
                            ->label('Until')
                            ->default($fiscal['date_to'])
                            ->required()
                            ->afterOrEqual('date_from')
                            ->live()
                            ->afterStateUpdated(function (ListIssuances $livewire): void {
                                $livewire->scheduleDeferredRsmiExportSize();
                            }),
                        Hidden::make('export_size_stamp')
                            ->default('0')
                            ->dehydrated(false),
                    ]),
            ])
            ->action(function (array $data, Action $action, ListIssuances $livewire): void {
                $format = (string) ($data['export_format'] ?? 'xlsx');
                $scope = (string) ($data['export_scope'] ?? 'all');
                $dateFrom = (string) ($data['date_from'] ?? '');
                $dateTo = (string) ($data['date_to'] ?? '');

                if (! in_array($format, ['xlsx', 'pdf'], true) || blank($dateFrom) || blank($dateTo)) {
                    Notification::make()
                        ->title('Export could not be started.')
                        ->danger()
                        ->send();

                    return;
                }

                $selectedIds = self::selectedIssuanceIds($livewire);

                if ($scope === 'selected' && $selectedIds === []) {
                    Notification::make()
                        ->title('No rows selected')
                        ->body('Select at least one issuance from the table, or export all issuances in the date range.')
                        ->danger()
                        ->send();
                    $action->halt();

                    return;
                }

                $categoryId = self::resolveCategoryId($livewire);
                $idsParam = $scope === 'selected' ? implode(',', $selectedIds) : null;

                $count = $livewire->countRsmiExportLines(
                    $dateFrom,
                    $dateTo,
                    $categoryId,
                    $scope === 'selected' ? $selectedIds : null,
                );

                if ($count <= 0) {
                    Notification::make()
                        ->title($scope === 'selected'
                            ? 'No selected issuances match the date range.'
                            : 'No issuances found for the selected date range.')
                        ->danger()
                        ->send();
                    $action->halt();

                    return;
                }

                $batchSize = StockLevelExportService::BATCH_SIZE;

                if ($format === 'pdf' && $count > StockLevelExportService::FAST_MAX) {
                    Notification::make()
                        ->title('Too many issuance lines to export.')
                        ->body('Narrow the date range or selection to '.$batchSize.' or fewer lines per file (max '.StockLevelExportService::FAST_MAX.').')
                        ->danger()
                        ->send();
                    $action->halt();

                    return;
                }

                $downloadUrl = route('owwa.export.bulk.issuances.rsmi', array_filter([
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'format' => $format === 'pdf' ? 'pdf' : null,
                    'category' => $categoryId > 0 ? $categoryId : null,
                    'ids' => $idsParam,
                    'back_url' => url()->previous(),
                ]));

                $message = $format === 'pdf' && $count > $batchSize
                    ? "Building RSMI ZIP ({$batchSize} lines per file)…"
                    : 'Building RSMI…';

                OwwaExportBusyDispatcher::start(
                    $livewire,
                    $downloadUrl,
                    $format === 'pdf' ? 'Preparing PDF export…' : 'Preparing Excel export…',
                    $message,
                    $format === 'pdf' ? 300000 : 120000,
                );
            });
    }

    public static function defaultExportScope(ListIssuances $livewire): string
    {
        return self::selectedIssuanceIds($livewire) !== [] ? 'selected' : 'all';
    }

    /**
     * @return list<int>
     */
    public static function selectedIssuanceIds(ListIssuances $livewire): array
    {
        if (! method_exists($livewire, 'getSelectedTableRecords')) {
            return [];
        }

        /** @var \Illuminate\Support\Collection<int, \App\Models\Issuance>|\Illuminate\Database\Eloquent\Collection<int, \App\Models\Issuance> $selected */
        $selected = $livewire->getSelectedTableRecords(shouldFetchSelectedRecords: true);

        return $selected
            ->map(fn ($record): int => (int) $record->getKey())
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    protected static function sizeHintLines(ListIssuances $livewire, string $format, string $scope): array
    {
        $batchSize = StockLevelExportService::BATCH_SIZE;
        $fastMax = StockLevelExportService::FAST_MAX;
        $selectedCount = count(self::selectedIssuanceIds($livewire));
        $lines = [];

        if ($scope === 'selected') {
            $lines[] = $selectedCount === 0
                ? 'No rows selected. Choose “All issuances in date range” or select rows from the table first.'
                : $selectedCount.' row(s) selected. Date range still applies.';
        }

        if (! $livewire->rsmiExportCountReady) {
            $lines[] = 'Counting issuance lines…';

            return $lines;
        }

        $count = (int) ($livewire->rsmiExportCount ?? 0);

        if ($count <= 0) {
            $lines[] = $scope === 'selected'
                ? 'No selected issuances match the date range.'
                : 'No issuance lines in the selected date range.';

            return $lines;
        }

        if ($format === 'xlsx') {
            $lines[] = "{$count} issuance line".($count === 1 ? '' : 's').' (Excel max '.$batchSize.').';

            return $lines;
        }

        if ($count > $fastMax) {
            $lines[] = "{$count} issuance lines — too many to export (max {$fastMax}). Narrow the date range or selection.";

            return $lines;
        }

        if ($count > $batchSize) {
            $lines[] = "{$count} issuance lines. Download is a ZIP of multiple PDFs ({$batchSize} lines each), not one PDF.";

            return $lines;
        }

        $lines[] = "{$count} issuance line".($count === 1 ? '' : 's').'. One PDF when ≤ '.$batchSize.' lines.';

        return $lines;
    }

    protected static function resolveCategoryId(mixed $livewire): int
    {
        $fromLivewire = null;

        if ($livewire instanceof LivewireComponent && property_exists($livewire, 'category') && filled($livewire->category)) {
            $fromLivewire = (int) $livewire->category;
        }

        return SyncsActiveItemCategory::resolveCategoryIdFromContext($fromLivewire);
    }

    /**
     * @return list<class-string>
     */
    public static function requiredTraits(): array
    {
        return [StartsOwwaExportBusy::class];
    }
}
