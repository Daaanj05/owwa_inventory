<?php

namespace App\Filament\Resources\Transfers\Actions;

use App\Filament\Resources\Transfers\TransferResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Transfer;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Livewire\Component as LivewireComponent;

class TransferViewActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(TransferResource::class, OwwaFormModalDefaults::WIDTH_STANDARD);
    }

    public static function exportOwwaAction(): Action
    {
        return self::exportAction('exportOwwa', 'Export Excel', false);
    }

    public static function exportPdfAction(): Action
    {
        return self::exportAction('exportOwwaPdf', 'Export PDF', true);
    }

    protected static function exportAction(string $name, string $label, bool $asPdf): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($asPdf ? 'heroicon-o-document-text' : 'heroicon-o-document-arrow-down')
            ->action(function (Transfer $record, Action $action) use ($asPdf): void {
                $url = route('owwa.export.transfer', $record).($asPdf ? '?format=pdf' : '');

                $livewire = $action->getLivewire();
                OwwaExportBusyDispatcher::start(
                    $livewire instanceof LivewireComponent ? $livewire : null,
                    $url,
                    $asPdf ? 'Preparing PDF export…' : 'Preparing Excel export…',
                    $asPdf ? 'Building your OWWA PDF…' : 'Building your OWWA form…',
                );
            });
    }

    public static function printViewAction(): Action
    {
        return Action::make('printView')
            ->label('Print Preview')
            ->icon('heroicon-o-printer')
            ->url(fn (Transfer $record): string => route('owwa.export.transfer', $record).'?format=pdf')
            ->openUrlInNewTab()
            ->visible(fn (Transfer $record): bool => in_array($record->item?->category?->getTemplateSlug(), ['ppe', 'semi_expendable'], true));
    }
}
