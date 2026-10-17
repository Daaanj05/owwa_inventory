<?php

namespace App\Filament\Resources\AiProcurementRunResource\Actions;

use App\Models\AiProcurementRun;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class AiProcurementRunViewActions
{
    /**
     * @return array<int, Action>
     */
    public static function modalFooterActions(): array
    {
        return [
            Action::make('approveRun')
                ->label('Mark approved')
                ->color('success')
                ->visible(fn (AiProcurementRun $record): bool => $record->status === 'pending')
                ->requiresConfirmation()
                ->modalHeading('Mark this run approved?')
                ->modalDescription('The run status changes to Approved. This cannot be undone from this screen.')
                ->modalSubmitActionLabel('Mark approved')
                ->action(function (AiProcurementRun $record): void {
                    $record->update(['status' => 'approved']);
                    $record->refresh();

                    Notification::make()
                        ->title('Run marked approved')
                        ->success()
                        ->send();
                }),
            Action::make('exportRunPdf')
                ->label('Export PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(fn (AiProcurementRun $record): string => route('ai-procurement-runs.export-pdf', $record))
                ->openUrlInNewTab(),
            Action::make('printRun')
                ->label('Open print view')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn (AiProcurementRun $record): string => route('ai-procurement-runs.print', $record))
                ->openUrlInNewTab(),
        ];
    }
}
