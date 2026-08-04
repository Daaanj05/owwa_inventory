<?php

namespace App\Filament\Resources\UserLogs\Schemas;

use App\Models\UserLog;
use App\Support\UserLogDisplay;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;

class UserLogInfolist
{
    /**
     * @return array<int, TextEntry|Section>
     */
    public static function modalDetailSections(): array
    {
        return [
            TextEntry::make('last_activity_at')
                ->label('Last activity')
                ->dateTime('M j, Y g:i A')
                ->placeholder('—'),
            TextEntry::make('session_duration')
                ->label('Duration')
                ->state(fn (UserLog $record): string => $record->sessionDurationLabel()),
            TextEntry::make('logout_reason')
                ->label('Logout reason')
                ->formatStateUsing(fn (?string $state, UserLog $record): string => UserLog::logoutReasonLabel($record->logout_reason))
                ->visible(fn (UserLog $record): bool => ! $record->isOpen()),
            TextEntry::make('panel')
                ->label('Panel')
                ->formatStateUsing(fn (?string $state): string => match ($state) {
                    'system-admin' => 'System Admin',
                    'admin' => 'Admin',
                    default => filled($state) ? str($state)->replace(['-', '_'], ' ')->title()->toString() : '—',
                })
                ->badge(),
            TextEntry::make('path')
                ->label('Page')
                ->state(fn (UserLog $record): string => UserLogDisplay::pathLabel($record->path, $record->panel))
                ->placeholder('—'),
            TextEntry::make('user_agent')
                ->label('Browser')
                ->state(fn (UserLog $record): string => UserLogDisplay::browserLabel($record->user_agent))
                ->placeholder('—')
                ->columnSpanFull(),
            Section::make('Session activity')
                ->schema([
                    ViewEntry::make('session_activities')
                        ->view('filament.partials.user-log-session-activities')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
