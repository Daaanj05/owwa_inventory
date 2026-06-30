<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Department;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $isUnitConsolidator = $user && $user->isUnitConsolidator();

        return $schema
            ->columns(1)
            ->components([
                Section::make('New user')
                    ->columnSpanFull()
                    ->columns(3)
                    ->compact()
                    ->visible(fn (string $context): bool => $context === 'create')
                    ->schema([
                        self::firstNameField(),
                        self::middleNameField(),
                        self::lastNameField(),
                        self::emailField()->columnSpan(2),
                        self::roleField($isUnitConsolidator)->columnSpan(1),
                        self::officeField($isUnitConsolidator, $user)->columnSpan(2),
                        self::departmentField($isUnitConsolidator, $user)->columnSpan(1),
                    ]),
                Section::make('Account information')
                    ->description('User login credentials and role assignment.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->visible(fn (string $context): bool => $context === 'edit')
                    ->schema([
                        self::firstNameField(),
                        self::middleNameField(),
                        self::lastNameField(),
                        self::emailField(),
                        self::passwordField(),
                        self::roleField($isUnitConsolidator)->columnSpanFull(),
                    ]),
                Section::make('Assignment')
                    ->description('Office and department this user belongs to.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->visible(fn (string $context): bool => $context === 'edit')
                    ->schema([
                        self::officeField($isUnitConsolidator, $user),
                        self::departmentField($isUnitConsolidator, $user),
                    ]),
            ]);
    }

    protected static function firstNameField(): TextInput
    {
        return TextInput::make('first_name')
            ->label('First name')
            ->required()
            ->maxLength(255);
    }

    protected static function middleNameField(): TextInput
    {
        return TextInput::make('middle_name')
            ->label('Middle name')
            ->maxLength(255);
    }

    protected static function lastNameField(): TextInput
    {
        return TextInput::make('last_name')
            ->label('Last name')
            ->required()
            ->maxLength(255);
    }

    protected static function emailField(): TextInput
    {
        return TextInput::make('email')
            ->label('Email address')
            ->email()
            ->required()
            ->unique(ignoreRecord: true)
            ->maxLength(255)
            ->validationMessages([
                'unique' => 'An account with this email address already exists.',
            ]);
    }

    protected static function passwordField(): TextInput
    {
        return TextInput::make('password')
            ->password()
            ->label('New password')
            ->helperText('Leave blank to keep the current password. A new password will be hashed on save.')
            ->dehydrated(fn ($state) => filled($state))
            ->minLength(8)
            ->maxLength(255)
            ->visible(fn (string $context): bool => $context === 'edit')
            ->columnSpanFull();
    }

    protected static function roleField(bool $isUnitConsolidator): Select
    {
        return Select::make('role')
            ->label('Role')
            ->options(
                $isUnitConsolidator
                    ? [User::ROLE_EMPLOYEE => 'Employee']
                    : [
                        User::ROLE_SUPPLY_CUSTODIAN => 'Supply Custodian',
                        User::ROLE_UNIT_CONSOLIDATOR => 'Unit Consolidator',
                        User::ROLE_EMPLOYEE => 'Employee',
                    ]
            )
            ->default(User::ROLE_EMPLOYEE)
            ->selectablePlaceholder(false)
            ->required()
            ->disabled($isUnitConsolidator)
            ->live();
    }

    protected static function officeField(bool $isUnitConsolidator, ?User $user): Select
    {
        return Select::make('office_id')
            ->label('Office')
            ->relationship(
                'office',
                'name',
                fn (Builder $query) => $isUnitConsolidator && $user?->office_id
                    ? $query->where('id', $user->office_id)
                    : $query
            )
            ->searchable()
            ->preload()
            ->placeholder('None')
            ->default($isUnitConsolidator ? $user?->office_id : null)
            ->required(fn (Get $get): bool => self::officeIsRequired($get, $isUnitConsolidator))
            ->live()
            ->afterStateUpdated(fn (Set $set) => $set('department_id', null));
    }

    protected static function departmentField(bool $isUnitConsolidator, ?User $user): Select
    {
        return Select::make('department_id')
            ->label('Department')
            ->options(fn (Get $get): array => self::departmentOptions($get, $isUnitConsolidator, $user))
            ->searchable()
            ->placeholder('None')
            ->default($isUnitConsolidator ? $user?->department_id : null)
            ->disabled(fn (Get $get): bool => blank($get('office_id')))
            ->rules(fn (Get $get): array => self::departmentRules($get));
    }

    protected static function officeIsRequired(Get $get, bool $isUnitConsolidator): bool
    {
        if ($isUnitConsolidator) {
            return true;
        }

        return in_array($get('role'), [
            User::ROLE_SUPPLY_CUSTODIAN,
            User::ROLE_UNIT_CONSOLIDATOR,
            User::ROLE_EMPLOYEE,
        ], true);
    }

    /**
     * @return array<int, string>
     */
    protected static function departmentOptions(Get $get, bool $isUnitConsolidator, ?User $user): array
    {
        $officeId = $isUnitConsolidator && $user?->office_id
            ? (int) $user->office_id
            : (filled($get('office_id')) ? (int) $get('office_id') : null);

        if ($officeId === null) {
            return [];
        }

        return Department::query()
            ->active()
            ->where('office_id', $officeId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    protected static function departmentRules(Get $get): array
    {
        if (blank($get('department_id'))) {
            return [];
        }

        if (blank($get('office_id'))) {
            return ['prohibited'];
        }

        return [
            Rule::exists('departments', 'id')->where(
                fn ($query) => $query->where('office_id', $get('office_id'))
            ),
        ];
    }
}
