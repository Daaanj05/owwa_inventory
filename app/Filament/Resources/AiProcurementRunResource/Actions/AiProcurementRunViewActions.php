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
            Action::make('markForApproval')
                ->label('Mark for approval')
                ->visible(fn (AiProcurementRun $record): bool => $record->status === 'draft')
                ->action(function (AiProcurementRun $record, Action $action): void {
                    $record->update(['status' => 'for_approval']);
                    $action->halt();
                }),
            Action::make('approveRun')
                ->label('Mark approved')
                ->color('success')
                ->visible(fn (AiProcurementRun $record): bool => in_array($record->status, ['draft', 'for_approval'], true))
                ->requiresConfirmation()
                ->action(function (AiProcurementRun $record, Action $action): void {
                    $record->update(['status' => 'approved']);
                    $action->halt();
                }),
            Action::make('archiveRun')
                ->label('Archive run')
                ->color('warning')
                ->visible(fn (AiProcurementRun $record): bool => $record->status !== 'archived')
                ->requiresConfirmation()
                ->action(function (AiProcurementRun $record, Action $action): void {
                    $record->update(['status' => 'archived']);
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
