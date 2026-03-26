<?php

namespace App\Filament\Resources\AiProcurementRunResource\Pages;

use App\Filament\Resources\AiProcurementRunResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAiProcurementRun extends ViewRecord
{
    protected static string $resource = AiProcurementRunResource::class;


    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Back to runs')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn () => AiProcurementRunResource::getUrl('index')),
            Actions\Action::make('markForApproval')
                ->label('Mark as for approval')
                ->visible(fn ($record) => $record->status === 'draft')
                ->action(fn ($record) => $record->update(['status' => 'for_approval'])),
            Actions\Action::make('approve')
                ->label('Mark as approved')
                ->color('success')
                ->visible(fn ($record) => in_array($record->status, ['draft', 'for_approval'], true))
                ->action(fn ($record) => $record->update(['status' => 'approved'])),
            Actions\Action::make('archive')
                ->label('Archive run')
                ->color('warning')
                ->visible(fn ($record) => $record->status !== 'archived')
                ->requiresConfirmation()
                ->action(fn ($record) => $record->update(['status' => 'archived'])),
            Actions\Action::make('print')
                ->label('Open print view')
                ->icon('heroicon-o-printer')
                ->url(fn ($record) => route('ai-procurement-runs.print', $record))
                ->openUrlInNewTab(),
        ];
    }
}

