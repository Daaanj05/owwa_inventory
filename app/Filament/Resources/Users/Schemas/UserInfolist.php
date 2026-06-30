<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

class UserInfolist
{
    /**
     * @return array<int, Section>
     */
    public static function modalDetailSections(): array
    {
        return [
            Section::make('Account details')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')
                        ->label('Name'),
                    TextEntry::make('email')
                        ->label('Email'),
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
                        ->placeholder('—'),
                    TextEntry::make('department.name')
                        ->label('Department')
                        ->placeholder('—'),
                ]),
        ];
    }
}
