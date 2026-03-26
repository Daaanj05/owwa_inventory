<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $isUnitHead = $user && $user->isAuthorizedPersonnel();

        return $schema
            ->components([
                Section::make('Account information')
                    ->description('User login credentials and role assignment.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->validationMessages([
                                'unique' => 'An account with this email address already exists.',
                            ]),
                        TextInput::make('password')
                            ->password()
                            ->required(fn (string $context): bool => $context === 'create')
                            ->dehydrated(fn ($state) => filled($state))
                            ->minLength(8)
                            ->same('password_confirmation')
                            ->maxLength(255),
                        TextInput::make('password_confirmation')
                            ->password()
                            ->label('Confirm password')
                            ->required(fn (string $context): bool => $context === 'create')
                            ->dehydrated(false)
                            ->minLength(8)
                            ->maxLength(255),
                        Select::make('role')
                            ->label('Role')
                            ->options(
                                $isUnitHead
                                    ? [User::ROLE_EMPLOYEE => 'Employee']
                                    : [
                                        User::ROLE_SYSTEM_ADMIN => 'System Admin',
                                        User::ROLE_SUPPLY_CUSTODIAN => 'Supply Custodian',
                                        User::ROLE_AUTHORIZED_PERSONNEL => 'Unit Head',
                                        User::ROLE_EMPLOYEE => 'Employee',
                                    ]
                            )
                            ->default(User::ROLE_EMPLOYEE)
                            ->required()
                            ->disabled($isUnitHead),
                    ]),
                Section::make('Assignment')
                    ->description('Office and department this user belongs to.')
                    ->columns(2)
                    ->schema([
                        Select::make('office_id')
                            ->label('Office')
                            ->relationship(
                                'office',
                                'name',
                                fn (Builder $query) => $isUnitHead && $user->office_id
                                    ? $query->where('id', $user->office_id)
                                    : $query
                            )
                            ->searchable()
                            ->preload()
                            ->placeholder('None')
                            ->default($isUnitHead ? $user->office_id : null)
                            ->required($isUnitHead),
                        Select::make('department_id')
                            ->label('Department')
                            ->relationship(
                                'department',
                                'name',
                                fn (Builder $query) => $isUnitHead && $user->office_id
                                    ? $query->where('office_id', $user->office_id)
                                    : $query
                            )
                            ->searchable()
                            ->preload()
                            ->placeholder('None')
                            ->default($isUnitHead ? $user->department_id : null),
                    ]),
            ]);
    }
}
