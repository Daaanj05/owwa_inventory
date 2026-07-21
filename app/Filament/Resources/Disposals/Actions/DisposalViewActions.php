<?php

namespace App\Filament\Resources\Disposals\Actions;

use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Disposal;
use App\Services\OwwaTemplateExportService;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Livewire\Component as LivewireComponent;

class DisposalViewActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(DisposalResource::class, OwwaFormModalDefaults::WIDTH_STANDARD);
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
            ->action(function (Disposal $record, Action $action) use ($asPdf): void {
                $form = app(OwwaTemplateExportService::class)->resolveDisposalFormSlug($record);
                $query = ['form' => $form];
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
            ->url(function (Disposal $record): string {
                $form = app(OwwaTemplateExportService::class)->resolveDisposalFormSlug($record);

                return route('owwa.export.disposal', $record).'?'.http_build_query([
                    'form' => $form,
                    'format' => 'pdf',
                ]);
            })
            ->openUrlInNewTab();
    }
}
