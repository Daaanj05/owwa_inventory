<?php

namespace App\Filament\Resources\Disposals\Actions;

use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Disposal;
use App\Services\OwwaTemplateExportService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Redirect;

class DisposalViewActions
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
                    ->options(fn (Disposal $record): array => app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('disposal', $record->item?->category))
                    ->default(fn (Disposal $record): string => app(OwwaTemplateExportService::class)->resolveDisposalFormSlug($record))
                    ->helperText('Auto-selected based on disposal type and category.'),
            ])
            ->action(function (Disposal $record, array $data) {
                $url = route('owwa.export.disposal', $record);
                if (! empty($data['form'])) {
                    $url .= '?form='.urlencode($data['form']);
                }

                return Redirect::away($url);
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
