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
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Export')
            ->modalCancelActionLabel('Close')
            ->mountUsing(function (ListIssuances $livewire): void {
                $livewire->rsmiExportCount = null;
                $livewire->rsmiExportCountReady = false;
                $livewire->rsmiExportSizeStamp = '0';
                $livewire->scheduleDeferredRsmiExportSize();
            })
            ->fillForm(fn (): array => [
                'export_format' => 'xlsx',
                'date_from' => $fiscal['date_from'],
                'date_to' => $fiscal['date_to'],
                'export_size_stamp' => '0',
            ])
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
                        Placeholder::make('selection_hint')
                            ->label('')
                            ->content(function (Get $get, ListIssuances $livewire): HtmlString {
                                $get('export_size_stamp');
                                $format = (string) ($get('export_format') ?? 'xlsx');
                                $lines = self::sizeHintLines($livewire, $format);

                                return new HtmlString(implode('<br><br>', array_map(
                                    fn (string $line): string => e($line),
                                    $lines,
                                )));
                            })
                            ->columnSpanFull(),
                    ]),
            ])
            ->action(function (array $data, Action $action, ListIssuances $livewire): void {
                $format = (string) ($data['export_format'] ?? 'xlsx');
                $dateFrom = (string) ($data['date_from'] ?? '');
                $dateTo = (string) ($data['date_to'] ?? '');

                if (! in_array($format, ['xlsx', 'pdf'], true) || blank($dateFrom) || blank($dateTo)) {
                    Notification::make()
                        ->title('Export could not be started.')
                        ->danger()
                        ->send();

                    return;
                }

                $categoryId = self::resolveCategoryId($livewire);

                if ($format === 'xlsx') {
                    $url = route('owwa.export.bulk.issuances.rsmi', array_filter([
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'category' => $categoryId > 0 ? $categoryId : null,
                        'back_url' => url()->previous(),
                    ]));

                    OwwaExportBusyDispatcher::start(
                        $livewire,
                        $url,
                        'Preparing Excel export…',
                        'Building RSMI for the selected date range…',
                        120000,
                    );

                    return;
                }

                $count = $livewire->countRsmiExportLines($dateFrom, $dateTo, $categoryId);

                if ($count <= 0) {
                    Notification::make()
                        ->title('No issuances found for the selected date range.')
                        ->danger()
                        ->send();
                    $action->halt();

                    return;
                }

                $batchSize = StockLevelExportService::BATCH_SIZE;

                if ($count > StockLevelExportService::FAST_MAX) {
                    Notification::make()
                        ->title('Too many issuance lines to export.')
                        ->body('Narrow the date range to '.$batchSize.' or fewer lines per file (max '.StockLevelExportService::FAST_MAX.').')
                        ->danger()
                        ->send();
                    $action->halt();

                    return;
                }

                $downloadUrl = route('owwa.export.bulk.issuances.rsmi', array_filter([
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'format' => 'pdf',
                    'category' => $categoryId > 0 ? $categoryId : null,
                    'back_url' => url()->previous(),
                ]));

                $message = $count > $batchSize
                    ? "Building RSMI ZIP ({$batchSize} lines per file)…"
                    : 'Building RSMI for the selected date range…';

                OwwaExportBusyDispatcher::start(
                    $livewire,
                    $downloadUrl,
                    'Preparing PDF export…',
                    $message,
                    300000,
                );
            });
    }

    /**
     * @return list<string>
     */
    protected static function sizeHintLines(ListIssuances $livewire, string $format): array
    {
        $batchSize = StockLevelExportService::BATCH_SIZE;
        $fastMax = StockLevelExportService::FAST_MAX;

        if (! $livewire->rsmiExportCountReady) {
            return ['Counting issuance lines…'];
        }

        $count = (int) ($livewire->rsmiExportCount ?? 0);

        if ($count <= 0) {
            return ['No issuance lines in the selected date range.'];
        }

        if ($format === 'xlsx') {
            return [
                "{$count} issuance line".($count === 1 ? '' : 's').' in range (Excel max '.$batchSize.').',
            ];
        }

        if ($count > $fastMax) {
            return [
                "{$count} issuance lines — too many to export (max {$fastMax}). Narrow the date range.",
            ];
        }

        if ($count > $batchSize) {
            return [
                "{$count} issuance lines. Download is a ZIP of multiple PDFs ({$batchSize} lines each), not one PDF.",
            ];
        }

        return [
            "{$count} issuance line".($count === 1 ? '' : 's').'. One PDF when ≤ '.$batchSize.' lines.',
        ];
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
