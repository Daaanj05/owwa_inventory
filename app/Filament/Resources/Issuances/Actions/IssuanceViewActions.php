<?php

namespace App\Filament\Resources\Issuances\Actions;

use App\Filament\Resources\Issuances\IssuanceResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Issuance;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Livewire\Component as LivewireComponent;

class IssuanceViewActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(IssuanceResource::class, OwwaFormModalDefaults::WIDTH_WIDE);
    }

    public static function exportOwwaAction(): Action
    {
        return Action::make('exportOwwa')
            ->label('Export')
            ->icon('heroicon-o-document-arrow-down')
            ->requiresConfirmation(fn (Issuance $record): bool => self::signatoriesIncomplete($record))
            ->modalHeading('Export without signatories?')
            ->modalDescription('Custodian / issued-by name is blank. Edit this issuance to add signatory names for the OWWA export, or continue with empty signature blocks on the form.')
            ->modalSubmitActionLabel('Export anyway')
            ->action(function (Issuance $record, Action $action): void {
                $livewire = $action->getLivewire();
                OwwaExportBusyDispatcher::start(
                    $livewire instanceof LivewireComponent ? $livewire : null,
                    route('owwa.export.issuance', $record),
                    'Preparing Excel export…',
                    'Building your OWWA form…',
                );
            });
    }

    public static function printQrLabelAction(): Action
    {
        return Action::make('printQrLabel')
            ->label('Print QR label')
            ->icon('heroicon-o-qr-code')
            ->visible(function (Issuance $record): bool {
                $slug = $record->item?->category?->getTemplateSlug();

                return in_array($slug, ['ppe', 'semi_expendable'], true)
                    && $record->batchLines()->count() === 1
                    && filled($record->property_number);
            })
            ->url(fn (Issuance $record): string => route('owwa.qr-labels.issuance', $record))
            ->openUrlInNewTab();
    }

    public static function printViewAction(): Action
    {
        return Action::make('printView')
            ->label('Print Preview')
            ->icon('heroicon-o-printer')
            ->url(function (Issuance $record): string {
                $slug = $record->item?->category?->getTemplateSlug();
                $form = $slug === 'ppe' ? 'par' : ($slug === 'semi_expendable' ? 'ics' : '');
                $url = route('owwa.print.issuance', $record);

                return $form !== '' ? $url.'?form='.$form : $url;
            })
            ->openUrlInNewTab();
    }

    protected static function signatoriesIncomplete(Issuance $record): bool
    {
        $record->loadMissing(['batch', 'issuedBy']);

        return blank($record->batch?->custodian_printed_name)
            && blank($record->custodian_printed_name)
            && blank($record->issuedBy?->name);
    }
}
