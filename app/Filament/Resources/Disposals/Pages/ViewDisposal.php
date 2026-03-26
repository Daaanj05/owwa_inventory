<?php

namespace App\Filament\Resources\Disposals\Pages;

use App\Filament\Resources\Disposals\DisposalResource;
use App\Services\OwwaTemplateExportService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Redirect;

class ViewDisposal extends ViewRecord
{
    protected static string $resource = DisposalResource::class;

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
                        ->options(fn (): array => app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('disposal', $this->record->item?->category))
                        ->default(function (): string {
                            $slug = $this->record->item?->category?->getTemplateSlug();
                            $type = $this->record->disposal_type;

                            if ($type === 'lost_stolen_damaged') {
                                return 'rlsddp';
                            }
                            if ($type === 'unserviceable') {
                                return $slug === 'semi_expendable' ? 'iirusp' : 'iirup';
                            }

                            $opts = app(OwwaTemplateExportService::class)->getAvailableFormsForCategory('disposal', $this->record->item?->category);

                            return array_key_first($opts) ?? '';
                        })
                        ->helperText('Auto-selected based on disposal type and category.'),
                ])
                ->action(function (array $data) {
                    $url = route('owwa.export.disposal', $this->record);
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
                    $type = $this->record->disposal_type;
                    $form = $type === 'lost_stolen_damaged' ? 'rlsddp' : ($type === 'unserviceable' ? ($slug === 'semi_expendable' ? 'iirusp' : 'iirup') : '');
                    $url = route('owwa.print.disposal', $this->record);

                    return $form !== '' ? $url . '?form=' . $form : $url;
                })
                ->openUrlInNewTab(),
        ];
    }
}
