<?php

namespace App\Filament\Support;

use App\Models\WorkflowEmailNotification;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;

class WorkflowEmailNotificationsInfolist
{
    public static function section(string $relationship = 'workflowEmailNotifications'): Section
    {
        return Section::make('Email notifications')
            ->columnSpanFull()
            ->collapsible()
            ->schema([
                RepeatableEntry::make($relationship)
                    ->label('')
                    ->schema([
                        TextEntry::make('user.name')
                            ->label('Recipient')
                            ->placeholder('—'),
                        TextEntry::make('title')
                            ->label('Subject')
                            ->placeholder('—'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state
                                ? WorkflowEmailNotification::statusLabel($state)
                                : '—')
                            ->color(fn (?string $state): string => $state
                                ? WorkflowEmailNotification::statusColor($state)
                                : 'gray'),
                        TextEntry::make('sent_at')
                            ->label('Sent')
                            ->dateTime('M j, Y g:i A')
                            ->placeholder(fn (WorkflowEmailNotification $record): string => match ($record->status) {
                                WorkflowEmailNotification::STATUS_QUEUED => 'Queued',
                                WorkflowEmailNotification::STATUS_SKIPPED => 'Skipped',
                                WorkflowEmailNotification::STATUS_FAILED => 'Failed',
                                default => '—',
                            }),
                        TextEntry::make('created_at')
                            ->label('Logged')
                            ->dateTime('M j, Y g:i A')
                            ->placeholder('—'),
                    ])
                    ->columns(5)
                    ->placeholder('No email notifications yet.'),
            ])
            ->visible(fn (?Model $record): bool => $record !== null);
    }
}
