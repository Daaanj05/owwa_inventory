<?php

namespace App\Filament\Resources\IncidentReports\Actions;

use App\Filament\Resources\IncidentReports\IncidentReportResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Disposal;
use App\Services\OwwaTemplateExportService;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Livewire\Component as LivewireComponent;

class IncidentReportViewActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(IncidentReportResource::class, OwwaFormModalDefaults::WIDTH_STANDARD);
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
            ->form([
                Select::make('form')
                    ->label('OWWA form')
                    ->options(fn (): array => app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('incident_report', null))
                    ->default('rlsddp'),
            ])
            ->action(function (Disposal $record, array $data, Action $action) use ($asPdf): void {
                $query = [
                    'form' => $data['form'] ?? 'rlsddp',
                ];
                if ($asPdf) {
                    $query['format'] = 'pdf';
                }
                $url = route('owwa.export.disposal', $record).'?'.http_build_query($query);

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
            ->url(fn (Disposal $record): string => route('owwa.print.disposal', $record).'?form=rlsddp')
            ->openUrlInNewTab();
    }
}
