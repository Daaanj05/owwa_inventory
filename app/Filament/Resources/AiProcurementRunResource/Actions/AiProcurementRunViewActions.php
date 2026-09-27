<?php

namespace App\Filament\Resources\AiProcurementRunResource\Actions;

use App\Models\AiProcurementRun;
use Filament\Actions\Action;

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
                ->action(function (AiProcurementRun $record, Action $action): void {
                    $record->update(['status' => 'approved']);
                    $action->halt();
                }),
            Action::make('printRun')
                ->label('Open print view')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn (AiProcurementRun $record): string => route('ai-procurement-runs.print', $record))
                ->openUrlInNewTab(),
        ];
    }
}
