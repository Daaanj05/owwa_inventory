<?php

namespace App\Filament\Resources\Transfers\Pages;

use App\Filament\Resources\Transfers\TransferResource;
use App\Services\OwwaTemplateExportService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Redirect;

class ViewTransfer extends ViewRecord
{
    protected static string $resource = TransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->modalWidth('5xl'),
            Action::make('exportOwwa')
                ->label('Export OWWA Form')
                ->icon('heroicon-o-document-arrow-down')
                ->form([
                    Select::make('form')
                        ->label('OWWA form')
                        ->options(fn (): array => app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('transfer', $this->record->item?->category))
                        ->default(function (): string {
                            $opts = app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('transfer', $this->record->item?->category);

                            return array_key_first($opts) ?? '';
                        })
                        ->helperText('The form is auto-selected based on the item category. Change only if needed.'),
                ])
                ->action(function (array $data) {
                    $url = route('owwa.export.transfer', $this->record);
                    if (! empty($data['form'])) {
                        $url .= '?form=' . urlencode($data['form']);
                    }

                    return Redirect::away($url);
                }),
            Action::make('printView')
                ->label('Print Preview')
                ->icon('heroicon-o-printer')
                ->url(fn (): string => route('owwa.print.transfer', $this->record))
                ->openUrlInNewTab()
                ->visible(fn (): bool => in_array($this->record->item?->category?->getTemplateSlug(), ['ppe', 'semi_expendable'], true)),
        ];
    }
}
