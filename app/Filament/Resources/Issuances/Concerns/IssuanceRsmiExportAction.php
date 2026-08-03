<?php

namespace App\Filament\Resources\Issuances\Concerns;

use App\Filament\Concerns\StartsOwwaExportBusy;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Livewire\Component as LivewireComponent;

/**
 * Consumables RSMI date-range export (Excel / PDF).
 */
final class IssuanceRsmiExportAction
{
    public static function make(): Action
    {
        return Action::make('exportRsmiReport')
            ->label('Export Report')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->modalHeading('Export options')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Export')
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
                            ->columnSpanFull(),
                        DatePicker::make('date_from')
                            ->label('From')
                            ->required(),
                        DatePicker::make('date_to')
                            ->label('Until')
                            ->required()
                            ->afterOrEqual('date_from'),
                    ]),
            ])
            ->action(function (array $data, Action $action): void {
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

                $livewire = $action->getLivewire();
                $categoryId = self::resolveCategoryId($livewire);

                $url = route('owwa.export.bulk.issuances.rsmi', array_filter([
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'format' => $format === 'pdf' ? 'pdf' : null,
                    'category' => $categoryId > 0 ? $categoryId : null,
                    'back_url' => url()->previous(),
                ]));

                $formatLabel = $format === 'pdf' ? 'PDF' : 'Excel';

                OwwaExportBusyDispatcher::start(
                    $livewire instanceof LivewireComponent ? $livewire : null,
                    $url,
                    'Preparing '.$formatLabel.' export…',
                    'Building RSMI for the selected date range…',
                );
            });
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
