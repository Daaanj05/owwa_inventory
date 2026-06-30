<?php

namespace App\Filament\Resources\UserLogs\Tables;

use App\Filament\Resources\UserLogs\Schemas\UserLogInfolist;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaModalSchema;
use App\Models\UserLog;
use App\Support\OwwaTransactionViewPresenter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('User')
                    ->sortable()
                    ->searchable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                TextColumn::make('logged_in_at')
                    ->label('Logged In At')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('logged_out_at')
                    ->label('Logged Out At')
                    ->formatStateUsing(fn (?string $state, UserLog $record): string => $record->isOpen()
                        ? 'Active'
                        : ($record->logged_out_at?->format('M j, Y g:i A') ?? '—'))
                    ->sortable(),
                TextColumn::make('logout_reason')
                    ->label('Logout Reason')
                    ->formatStateUsing(fn (?string $state): string => UserLog::logoutReasonLabel($state))
                    ->badge()
                    ->toggleable(),
                TextColumn::make('session_actions_count')
                    ->label('Actions')
                    ->state(fn (UserLog $record): int => $record->sessionActivitiesCount())
                    ->sortable(false),
                TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('panel')
                    ->label('Panel')
                    ->sortable()
                    ->badge(),
                TextColumn::make('archived_at')
                    ->label('Archived')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('logged_in_at', 'desc')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    OwwaModalSchema::withHero(
                        fn (UserLog $record): array => OwwaTransactionViewPresenter::forUserLog($record),
                        UserLogInfolist::modalDetailSections(),
                    ),
                ),
            ])
            ->recordUrl(null)
            ->recordAction('view');
    }
}
