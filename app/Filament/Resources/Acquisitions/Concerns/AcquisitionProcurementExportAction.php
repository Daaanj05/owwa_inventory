<?php

namespace App\Filament\Resources\Acquisitions\Concerns;

use App\Filament\Concerns\StartsOwwaExportBusy;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Livewire\Component as LivewireComponent;

/**
 * Shared date-range PR/PO/IAR export for acquisition document list pages.
 */
final class AcquisitionProcurementExportAction
{
    public static function make(string $defaultDocumentType = 'pr'): Action
    {
        return Action::make('exportProcurementReport')
            ->label('Export Report')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->modalHeading('Export options')
            ->modalSubmitActionLabel('Export')
            ->form([
                Select::make('document_type')
                    ->label('Document')
                    ->options([
                        'pr' => 'Purchase Request (PR)',
                        'po' => 'Purchase Order (PO)',
                        'iar' => 'Inspection & Acceptance (IAR)',
                    ])
                    ->default($defaultDocumentType)
                    ->required()
                    ->selectablePlaceholder(false),
                Select::make('export_format')
                    ->label('Format')
                    ->options([
                        'xlsx' => 'Excel',
                        'pdf' => 'PDF',
                    ])
                    ->default('xlsx')
                    ->required()
                    ->selectablePlaceholder(false),
                DatePicker::make('date_from')
                    ->label('From')
                    ->required(),
                DatePicker::make('date_to')
                    ->label('Until')
                    ->required()
                    ->afterOrEqual('date_from'),
            ])
            ->action(function (array $data, Action $action): void {
                $documentType = (string) ($data['document_type'] ?? '');
                $format = (string) ($data['export_format'] ?? 'xlsx');
                $dateFrom = (string) ($data['date_from'] ?? '');
                $dateTo = (string) ($data['date_to'] ?? '');

                if (! in_array($documentType, ['pr', 'po', 'iar'], true)
                    || ! in_array($format, ['xlsx', 'pdf'], true)
                    || blank($dateFrom)
                    || blank($dateTo)) {
                    Notification::make()
                        ->title('Export could not be started.')
                        ->danger()
                        ->send();

                    return;
                }

                $livewire = $action->getLivewire();
                $categoryId = self::resolveCategoryId($livewire);

                $url = route('owwa.export.bulk.procurement', array_filter([
                    'document_type' => $documentType,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'format' => $format === 'pdf' ? 'pdf' : null,
                    'category' => $categoryId > 0 ? $categoryId : null,
                    'back_url' => url()->previous(),
                ]));

                $label = match ($documentType) {
                    'po' => 'purchase orders',
                    'iar' => 'inspection reports',
                    default => 'purchase requests',
                };
                $formatLabel = $format === 'pdf' ? 'PDF' : 'Excel';

                OwwaExportBusyDispatcher::start(
                    $livewire instanceof LivewireComponent ? $livewire : null,
                    $url,
                    'Preparing '.$formatLabel.' export…',
                    'Building '.$label.' for the selected date range…',
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
