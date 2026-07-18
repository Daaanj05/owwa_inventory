<?php

namespace App\Filament\Resources\Transfers\Actions;

use App\Filament\Resources\Transfers\TransferResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Transfer;
use App\Services\OwwaTemplateExportService;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Livewire\Component as LivewireComponent;

class TransferViewActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(TransferResource::class, OwwaFormModalDefaults::WIDTH_STANDARD);
    }

    public static function exportOwwaAction(): Action
    {
        return Action::make('exportOwwa')
            ->label('Export OWWA Form')
            ->icon('heroicon-o-document-arrow-down')
            ->form([
                Select::make('form')
                    ->label('OWWA form')
                    ->options(fn (Transfer $record): array => app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('transfer', $record->item?->category))
                    ->default(function (Transfer $record): string {
                        $opts = app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('transfer', $record->item?->category);

                        return array_key_first($opts) ?? '';
                    })
                    ->helperText('The form is auto-selected based on the item category. Change only if needed.'),
            ])
            ->action(function (Transfer $record, array $data, Action $action): void {
                $url = route('owwa.export.transfer', $record);
                if (! empty($data['form'])) {
                    $url .= '?form='.urlencode($data['form']);
                }

                $livewire = $action->getLivewire();
                OwwaExportBusyDispatcher::start(
                    $livewire instanceof LivewireComponent ? $livewire : null,
                    $url,
                    'Preparing Excel export…',
                    'Building your OWWA form…',
                );
            });
    }

    public static function printViewAction(): Action
    {
        return Action::make('printView')
            ->label('Print Preview')
            ->icon('heroicon-o-printer')
            ->url(fn (Transfer $record): string => route('owwa.print.transfer', $record))
            ->openUrlInNewTab()
            ->visible(fn (Transfer $record): bool => in_array($record->item?->category?->getTemplateSlug(), ['ppe', 'semi_expendable'], true));
    }
}
