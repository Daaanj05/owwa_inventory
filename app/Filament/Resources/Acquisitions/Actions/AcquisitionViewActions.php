<?php

namespace App\Filament\Resources\Acquisitions\Actions;

use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Acquisition;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Livewire\Component as LivewireComponent;

class AcquisitionViewActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(AcquisitionResource::class, OwwaFormModalDefaults::WIDTH_COMPACT);
    }

    public static function exportOwwaAction(): Action
    {
        return self::exportAction('exportOwwa', false);
    }

    public static function exportPdfAction(): Action
    {
        return self::exportAction('exportOwwaPdf', true);
    }

    protected static function exportAction(string $name, bool $asPdf): Action
    {
        return Action::make($name)
            ->label(fn (Acquisition $record) => match ($record->item?->category?->getTemplateSlug()) {
                'ppe' => $asPdf ? 'Export Property Card (PDF)' : 'Export receipt line (Property Card)',
                'semi_expendable' => $asPdf ? 'Export Annex A.1 (PDF)' : 'Export receipt line (Annex A.1)',
                default => $asPdf ? 'Export Stock Card (PDF)' : 'Export Stock Card receipt (Appendix 58)',
            })
            ->icon($asPdf ? 'heroicon-o-document-text' : 'heroicon-o-document-arrow-down')
            ->action(function (Acquisition $record, Action $action) use ($asPdf): void {
                $url = route('owwa.export.acquisition', $record).($asPdf ? '?format=pdf' : '');

                $livewire = $action->getLivewire();
                OwwaExportBusyDispatcher::start(
                    $livewire instanceof LivewireComponent ? $livewire : null,
                    $url,
                    $asPdf ? 'Preparing PDF export…' : 'Preparing Excel export…',
                    $asPdf ? 'Building your OWWA PDF…' : 'Building your OWWA form…',
                );
            });
    }
}
