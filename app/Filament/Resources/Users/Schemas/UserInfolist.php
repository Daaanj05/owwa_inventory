<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

class UserInfolist
{
    /**
     * @return array<int, TextEntry|Section>
     */
    public static function modalDetailSections(): array
    {
        return [
            TextEntry::make('name')
                ->label('Name'),
            TextEntry::make('email')
                ->label('Email'),
            TextEntry::make('email_verified_at')
                ->label('Verification')
                ->badge()
                ->formatStateUsing(fn (?string $state, User $record): string => $record->hasVerifiedEmail() ? 'Verified' : 'Pending')
                ->color(fn (User $record): string => $record->hasVerifiedEmail() ? 'success' : 'warning'),
            TextEntry::make('role')
                ->label('Role')
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    User::ROLE_SYSTEM_ADMIN => 'System Admin',
                    User::ROLE_SUPPLY_CUSTODIAN => 'Supply Custodian',
                    User::ROLE_UNIT_CONSOLIDATOR => 'Unit Consolidator',
                    User::ROLE_EMPLOYEE => 'Employee',
                    default => $state,
                })
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    User::ROLE_SYSTEM_ADMIN => 'danger',
                    User::ROLE_SUPPLY_CUSTODIAN => 'primary',
                    User::ROLE_UNIT_CONSOLIDATOR => 'info',
                    default => 'gray',
                }),
            TextEntry::make('office.name')
                ->label('Office')
                ->placeholder('—')
                ->visible(fn (User $record): bool => ! $record->isUnitConsolidator()),
            TextEntry::make('department.name')
                ->label('Sub-Office/Department')
                ->placeholder('—')
                ->visible(fn (User $record): bool => ! $record->isUnitConsolidator()),
            Section::make('Handled offices & sub-offices/departments')
                ->visible(fn (User $record): bool => $record->isUnitConsolidator())
                ->schema([
                    RepeatableEntry::make('assignments')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('office.name')
                                ->label('Office'),
                            TextEntry::make('department.name')
                                ->label('Sub-Office/Department'),
                        ])
                        ->columns(2),
                ]),
        ];
    }
}
