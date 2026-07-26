<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Department;
use App\Models\Office;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $isUnitConsolidator = $user && $user->isUnitConsolidator();

        return $schema
            ->columns(fn (?string $operation = null, ?string $schemaOperation = null): int => self::isCreateOperation(self::resolvedOperation($operation, $schemaOperation)) ? 2 : 1)
            ->components([
                self::firstNameField()
                    ->visible(fn (?string $operation = null, ?string $schemaOperation = null): bool => self::showsCreateOrEditFields($operation, $schemaOperation)),
                self::lastNameField()
                    ->visible(fn (?string $operation = null, ?string $schemaOperation = null): bool => self::showsCreateOrEditFields($operation, $schemaOperation)),
                self::middleNameField()
                    ->visible(fn (?string $operation = null, ?string $schemaOperation = null): bool => self::showsCreateOrEditFields($operation, $schemaOperation)),
                self::emailField()
                    ->visible(fn (?string $operation = null, ?string $schemaOperation = null): bool => self::showsCreateOrEditFields($operation, $schemaOperation)),
                self::roleField($isUnitConsolidator)
                    ->visible(fn (?string $operation = null, ?string $schemaOperation = null): bool => self::showsCreateOrEditFields($operation, $schemaOperation)),
                self::passwordField()
                    ->visible(fn (?string $operation = null, ?string $schemaOperation = null): bool => self::isEditOperation(self::resolvedOperation($operation, $schemaOperation))),
                self::officeField($isUnitConsolidator, $user)
                    ->visible(fn (?string $operation, ?string $schemaOperation, Get $get): bool => self::showsCreateOrEditFields($operation, $schemaOperation)
                        && ! self::isUnitConsolidatorRole($get)),
                self::departmentField($isUnitConsolidator, $user)
                    ->visible(fn (?string $operation, ?string $schemaOperation, Get $get): bool => self::showsCreateOrEditFields($operation, $schemaOperation)
                        && ! self::isUnitConsolidatorRole($get)),
                self::officeGroupsRepeater()
                    ->visible(fn (?string $operation, ?string $schemaOperation, Get $get): bool => self::showsCreateOrEditFields($operation, $schemaOperation)
                        && self::isUnitConsolidatorRole($get))
                    ->columnSpanFull(),
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
            ->visible(fn (?string $operation = null, ?string $schemaOperation = null): bool => self::isEditOperation(self::resolvedOperation($operation, $schemaOperation)))
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
                    : $query->active()
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
            ->label('Sub-Office/Department')
            ->options(fn (Get $get): array => self::departmentOptions($get, $isUnitConsolidator, $user))
            ->searchable()
            ->placeholder('None')
            ->default($isUnitConsolidator ? $user?->department_id : null)
            ->disabled(fn (Get $get): bool => blank($get('office_id')))
            ->rules(fn (Get $get): array => self::departmentRules($get));
    }

    protected static function officeGroupsRepeater(): Repeater
    {
        return Repeater::make('office_groups')
            ->label('Handled offices & sub-offices/departments')
            ->table([
                TableColumn::make('Office')
                    ->markAsRequired()
                    ->alignment(Alignment::Start)
                    ->width('45%'),
                TableColumn::make('Sub-Office/Department')
                    ->markAsRequired()
                    ->alignment(Alignment::Start)
                    ->width('55%'),
            ])
            ->compact()
            ->schema([
                Select::make('office_id')
                    ->label('Office')
                    ->hiddenLabel()
                    ->options(fn (): array => Office::query()
                        ->active()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->searchable()
                    ->selectablePlaceholder(false)
                    ->live()
                    ->afterStateUpdated(fn (Set $set): mixed => $set('departments', [null])),
                Repeater::make('departments')
                    ->hiddenLabel()
                    ->simple(
                        Select::make('department_id')
                            ->options(fn (Get $get): array => self::groupedDepartmentOptions($get))
                            ->required()
                            ->searchable()
                            ->selectablePlaceholder(false)
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                            ->disabled(fn (Get $get): bool => blank(self::resolvedOfficeIdFromGroup($get)))
                            ->rules(fn (Get $get): array => self::groupedAssignmentDepartmentRules($get)),
                    )
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel('Add sub-office/department')
                    ->deleteAction(self::repeaterDeleteVisibleExceptFirst())
                    ->reorderable(false)
                    ->extraAttributes(['class' => 'owwa-uc-departments-repeater']),
            ])
            ->minItems(1)
            ->defaultItems(1)
            ->addActionLabel('Add another office')
            ->deleteAction(self::repeaterDeleteVisibleExceptFirst())
            ->reorderable(false)
            ->required(fn (Get $get): bool => self::isUnitConsolidatorRole($get))
            ->extraAttributes(['class' => 'owwa-uc-office-groups-repeater'])
            ->rules([
                fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                    $groups = is_array($value) ? $value : [];

                    $officeIds = collect($groups)
                        ->pluck('office_id')
                        ->filter()
                        ->map(fn (mixed $id): int => (int) $id);

                    if ($officeIds->count() !== $officeIds->unique()->count()) {
                        $fail('Each office may only be added once. Add more departments under the existing office entry.');

                        return;
                    }

                    $departmentIds = collect($groups)
                        ->flatMap(function (array $group): array {
                            $departments = is_array($group['departments'] ?? null) ? $group['departments'] : [];

                            return collect($departments)
                                ->map(function (mixed $departmentRow): ?int {
                                    if (is_array($departmentRow)) {
                                        $id = (int) ($departmentRow['department_id'] ?? 0);

                                        return $id > 0 ? $id : null;
                                    }

                                    $id = (int) $departmentRow;

                                    return $id > 0 ? $id : null;
                                })
                                ->filter()
                                ->all();
                        });

                    if ($departmentIds->count() !== $departmentIds->unique()->count()) {
                        $fail('Each sub-office/department may only be assigned once.');
                    }
                },
            ]);
    }

    protected static function repeaterDeleteVisibleExceptFirst(): Closure
    {
        return fn (Action $action): Action => $action->visible(function (array $arguments, Repeater $component): bool {
            if (! $component->isDeletable()) {
                return false;
            }

            $itemKeys = array_keys($component->getRawState() ?? []);
            $index = array_search($arguments['item'] ?? null, $itemKeys, true);

            return $index !== false && $index > 0;
        });
    }

    protected static function resolvedOfficeIdFromGroup(Get $get): ?int
    {
        if (filled($get('office_id'))) {
            return (int) $get('office_id');
        }

        if (filled($get('../../office_id'))) {
            return (int) $get('../../office_id');
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected static function groupedDepartmentOptions(Get $get): array
    {
        $officeId = self::resolvedOfficeIdFromGroup($get);

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
    protected static function groupedAssignmentDepartmentRules(Get $get): array
    {
        if (blank($get('department_id'))) {
            return ['required'];
        }

        $officeId = self::resolvedOfficeIdFromGroup($get);

        if ($officeId === null) {
            return ['prohibited'];
        }

        return [
            Rule::exists('departments', 'id')->where(
                fn ($query) => $query->where('office_id', $officeId)
            ),
        ];
    }

    protected static function isUnitConsolidatorRole(Get $get): bool
    {
        return $get('role') === User::ROLE_UNIT_CONSOLIDATOR;
    }

    protected static function officeIsRequired(Get $get, bool $isUnitConsolidator): bool
    {
        if (self::isUnitConsolidatorRole($get)) {
            return false;
        }

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

    protected static function resolvedOperation(?string $operation = null, ?string $schemaOperation = null): string
    {
        return $operation ?? $schemaOperation ?? 'create';
    }

    protected static function showsCreateOrEditFields(?string $operation = null, ?string $schemaOperation = null): bool
    {
        $resolved = self::resolvedOperation($operation, $schemaOperation);

        return self::isCreateOperation($resolved) || self::isEditOperation($resolved);
    }

    protected static function isCreateOperation(string $operation): bool
    {
        return $operation === 'create' || str_ends_with($operation, '.create');
    }

    protected static function isEditOperation(string $operation): bool
    {
        return $operation === 'edit' || str_ends_with($operation, '.edit');
    }
}
