<?php

namespace App\Filament\Resources\Disposals\Actions;

use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Disposal;
use App\Services\DisposalWorkflowService;
use App\Services\OwwaTemplateExportService;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Livewire\Component as LivewireComponent;
use Throwable;

class DisposalViewActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(DisposalResource::class, OwwaFormModalDefaults::WIDTH_STANDARD)
            ->visible(fn (Disposal $record): bool => $record->isEditable());
    }

    public static function confirmAction(): Action
    {
        return Action::make('confirmDisposal')
            ->label('Confirm Disposal')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Confirm disposal?')
            ->modalDescription('This applies the stock write-off and locks the record from further edits.')
            ->modalSubmitActionLabel('Confirm')
            ->visible(fn (Disposal $record): bool => ! $record->isConfirmed())
            ->action(function (Disposal $record): void {
                try {
                    app(DisposalWorkflowService::class)->confirm($record);
                    Notification::make()
                        ->title('Disposal confirmed')
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Could not confirm disposal')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
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
}
