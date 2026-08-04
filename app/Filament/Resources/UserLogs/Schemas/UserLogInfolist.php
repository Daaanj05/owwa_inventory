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
     * @return array<int, Section>
     */
    public static function modalDetailSections(): array
    {
        return [
            Section::make()
                ->columnSpanFull()
                ->columns(3)
                ->compact()
                ->schema([
                    TextEntry::make('last_activity_at')
                        ->label('Last activity')
                        ->dateTime('M j, Y g:i A')
                        ->placeholder('—'),
                    TextEntry::make('session_duration')
                        ->label('Duration')
                        ->state(fn (UserLog $record): string => $record->sessionDurationLabel()),
                    TextEntry::make('where')
                        ->label('Where')
                        ->state(fn (UserLog $record): string => UserLogDisplay::whereLabel($record->path, $record->panel))
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('user_agent')
                        ->label('Browser')
                        ->state(fn (UserLog $record): string => UserLogDisplay::browserLabel($record->user_agent))
                        ->placeholder('—')
                        ->columnSpan(fn (UserLog $record): int => $record->isOpen() ? 3 : 2),
                    TextEntry::make('logout_reason')
                        ->label('Logout reason')
                        ->formatStateUsing(fn (?string $state, UserLog $record): string => UserLog::logoutReasonLabel($record->logout_reason))
                        ->visible(fn (UserLog $record): bool => ! $record->isOpen()),
                ]),
            Section::make('Session activity')
                ->columnSpanFull()
                ->compact()
                ->schema([
                    ViewEntry::make('session_activities')
                        ->view('filament.partials.user-log-session-activities')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
