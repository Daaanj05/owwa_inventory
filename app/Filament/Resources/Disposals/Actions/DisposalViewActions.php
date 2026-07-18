<?php

namespace App\Filament\Resources\Disposals\Actions;

use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Disposal;
use App\Services\OwwaTemplateExportService;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Livewire\Component as LivewireComponent;

class DisposalViewActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(DisposalResource::class, OwwaFormModalDefaults::WIDTH_STANDARD);
    }

    public static function exportOwwaAction(): Action
    {
        return Action::make('exportOwwa')
            ->label('Export OWWA Form')
            ->icon('heroicon-o-document-arrow-down')
            ->form([
                Select::make('form')
                    ->label('OWWA form')
                    ->options(fn (Disposal $record): array => app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('disposal', $record->item?->category))
                    ->default(fn (Disposal $record): string => app(OwwaTemplateExportService::class)->resolveDisposalFormSlug($record))
                    ->helperText('Auto-selected based on disposal type and category.'),
            ])
            ->action(function (Disposal $record, array $data, Action $action): void {
                $url = route('owwa.export.disposal', $record);
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
            ->url(function (Disposal $record): string {
                $form = app(OwwaTemplateExportService::class)->resolveDisposalFormSlug($record);
                $url = route('owwa.print.disposal', $record);

                return $form !== 'default' ? $url.'?form='.$form : $url;
            })
            ->openUrlInNewTab();
    }
}
