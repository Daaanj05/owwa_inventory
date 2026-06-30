<?php

namespace App\Filament\Resources\UserLogs\Schemas;

use App\Models\UserLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;

class UserLogInfolist
{
    /**
     * @return array<int, Section>
     */
    public static function modalDetailSections(): array
    {
        return [
            Section::make('Session details')
                ->columns(2)
                ->schema([
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
                        ->badge(),
                    TextEntry::make('path')
                        ->label('Path')
                        ->placeholder('—'),
                    TextEntry::make('user_agent')
                        ->label('User agent')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
            Section::make('Session activity')
                ->schema([
                    ViewEntry::make('session_activities')
                        ->view('filament.partials.user-log-session-activities')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
