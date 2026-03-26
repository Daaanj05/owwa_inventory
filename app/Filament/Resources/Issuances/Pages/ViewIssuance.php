<?php

namespace App\Filament\Resources\Issuances\Pages;

use App\Filament\Resources\Issuances\IssuanceResource;
use App\Services\OwwaTemplateExportService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Redirect;

class ViewIssuance extends ViewRecord
{
    protected static string $resource = IssuanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->modalWidth('7xl'),
            Action::make('exportOwwa')
                ->label('Export OWWA Form')
                ->icon('heroicon-o-document-arrow-down')
                ->form([
                    Select::make('form')
                        ->label('OWWA form')
                        ->options(fn (): array => app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('issuance', $this->record->item?->category))
                        ->default(function (): string {
                            $opts = app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('issuance', $this->record->item?->category);

                            return array_key_first($opts) ?? '';
                        })
                        ->helperText('The form is auto-selected based on the item category. Change only if needed.'),
                ])
                ->action(function (array $data) {
                    $url = route('owwa.export.issuance', $this->record);
                    if (! empty($data['form'])) {
                        $url .= '?form=' . urlencode($data['form']);
                    }

                    return Redirect::away($url);
                }),
            Action::make('printView')
                ->label('Print Preview')
                ->icon('heroicon-o-printer')
                ->url(function (): string {
                    $slug = $this->record->item?->category?->getTemplateSlug();
                    $form = $slug === 'ppe' ? 'par' : ($slug === 'semi_expendable' ? 'ics' : '');
                    $url = route('owwa.print.issuance', $this->record);

                    return $form !== '' ? $url . '?form=' . $form : $url;
                })
                ->openUrlInNewTab(),
        ];
    }
}
