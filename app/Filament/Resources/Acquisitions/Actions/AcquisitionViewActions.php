<?php

namespace App\Filament\Resources\Acquisitions\Actions;

use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Acquisition;
use App\Services\OwwaTemplateExportService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Redirect;

class AcquisitionViewActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_COMPACT);
    }

    public static function exportOwwaAction(): Action
    {
        return Action::make('exportOwwa')
            ->label(fn (Acquisition $record): string => match ($record->item?->category?->getTemplateSlug()) {
                'ppe' => 'Export receipt line (Property Card)',
                'semi_expendable' => 'Export receipt line (Annex A.1)',
                default => 'Export Stock Card receipt (Appendix 58)',
            })
            ->icon('heroicon-o-document-arrow-down')
            ->form([
                Select::make('form')
                    ->label('OWWA form')
                    ->options(fn (Acquisition $record): array => app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('acquisition', $record->item?->category))
                    ->default(function (Acquisition $record): string {
                        $opts = app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('acquisition', $record->item?->category);

                        return array_key_first($opts) ?? '';
                    })
                    ->helperText(fn (Acquisition $record): string => match ($record->item?->category?->getTemplateSlug()) {
                        'ppe' => 'Use Acquisitions → PR / PO / IAR paperwork for purchase forms. This export posts one receipt row to Appendix 69. For the full card with all movements, open Stock levels and export from the item ledger.',
                        'semi_expendable' => 'Use Acquisitions → PR / PO / IAR paperwork for purchase forms. This export posts one receipt row to Annex A.1. For the full property card, open Stock levels and export from the item ledger.',
                        default => 'Use Acquisitions → PR / PO / IAR paperwork for purchase forms. This export posts one receipt row to the Stock Card (Appendix 58). For the full ledger, open Stock levels and export from the item modal.',
                    }),
            ])
            ->action(function (Acquisition $record, array $data) {
                $url = route('owwa.export.acquisition', $record);
                if (! empty($data['form'])) {
                    $url .= '?form='.urlencode($data['form']);
                }

                return Redirect::away($url);
            });
    }
}
