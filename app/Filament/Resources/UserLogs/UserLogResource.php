<?php

namespace App\Filament\Resources\UserLogs;

use App\Filament\Resources\UserLogs\Pages\ListUserLogs;
use App\Models\UserLog;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class UserLogResource extends Resource
{
    protected static ?string $model = UserLog::class;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'Login Audit Log';

    protected static ?string $pluralModelLabel = 'Login Audit Logs';

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->isSystemAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('panel')
                    ->label('Panel')
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('path')
                    ->label('Path')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('user_agent')
                    ->label('User Agent')
                    ->limit(50)
                    ->tooltip(fn ($state) => $state)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('logged_in_at')
                    ->label('Logged In At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('logged_in_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUserLogs::route('/'),
        ];
    }
}

