<?php

namespace App\Filament\Resources\IncidentReports\Actions;

use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Disposal;
use App\Services\OwwaTemplateExportService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Redirect;

class IncidentReportViewActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_STANDARD);
    }

    public static function exportOwwaAction(): Action
    {
        return Action::make('exportOwwa')
            ->label('Export OWWA Form')
            ->icon('heroicon-o-document-arrow-down')
            ->form([
                Select::make('form')
                    ->label('OWWA form')
                    ->options(fn (): array => app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('incident_report', null))
                    ->default('rlsddp'),
            ])
            ->action(function (Disposal $record, array $data) {
                $url = route('owwa.export.disposal', $record);
                $form = $data['form'] ?? 'rlsddp';
                if ($form !== '') {
                    $url .= '?form='.urlencode($form);
                }

                return Redirect::away($url);
            });
    }

    public static function printViewAction(): Action
    {
        return Action::make('printView')
            ->label('Print Preview')
            ->icon('heroicon-o-printer')
            ->url(fn (Disposal $record): string => route('owwa.print.disposal', $record).'?form=rlsddp')
            ->openUrlInNewTab();
    }
}
