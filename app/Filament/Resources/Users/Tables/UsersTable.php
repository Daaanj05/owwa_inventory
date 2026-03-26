<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(function (string $state): string {
                        return match ($state) {
                            User::ROLE_SYSTEM_ADMIN => 'System Admin',
                            User::ROLE_SUPPLY_CUSTODIAN => 'Supply Custodian',
                            User::ROLE_AUTHORIZED_PERSONNEL => 'Unit Head',
                            User::ROLE_EMPLOYEE => 'Employee',
                            default => $state,
                        };
                    })
                    ->color(function (string $state): string {
                        return match ($state) {
                            User::ROLE_SYSTEM_ADMIN => 'danger',
                            User::ROLE_SUPPLY_CUSTODIAN => 'primary',
                            User::ROLE_AUTHORIZED_PERSONNEL => 'info',
                            default => 'gray',
                        };
                    })
                    ->sortable(),
                TextColumn::make('office.name')
                    ->label('Office')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('department.name')
                    ->label('Department')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('role')
                    ->options([
                        User::ROLE_SYSTEM_ADMIN        => 'System Admin',
                        User::ROLE_SUPPLY_CUSTODIAN    => 'Supply Custodian',
                        User::ROLE_AUTHORIZED_PERSONNEL => 'Unit Head',
                        User::ROLE_EMPLOYEE            => 'Employee',
                    ])
                    ->placeholder('All roles'),
                SelectFilter::make('office_id')
                    ->label('Office')
                    ->relationship('office', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('All offices'),
            ])
            ->emptyStateHeading('No users yet')
            ->emptyStateDescription('Add system users here. Supply Custodians can approve requisitions and manage inventory.')
            ->emptyStateIcon('heroicon-o-users')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
