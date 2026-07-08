<?php

namespace App\Filament\Resources\Departments\Schemas;

use App\Rules\UniqueDepartmentNameInOffice;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;

class DepartmentForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeActive = fn ($query) => $query->active();

        return $schema
            ->columns(1)
            ->components([
                Select::make('office_id')
                    ->label('Office')
                    ->relationship('office', 'name', $scopeActive)
                    ->required()
                    ->validationMessages([
                        'required' => 'Please select an office.',
                    ])
                    ->searchable()
                    ->preload()
                    ->visible(fn (string $operation): bool => self::isCreateOperation($operation) || self::isEditOperation($operation)),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->rules([new UniqueDepartmentNameInOffice])
                    ->visible(fn (string $operation): bool => self::isEditOperation($operation)),
                TextInput::make('code')
                    ->label('Code')
                    ->placeholder('e.g. HR, FIN')
                    ->maxLength(20)
                    ->visible(fn (string $operation): bool => self::isEditOperation($operation)),
                Repeater::make('lines')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Name')
                            ->markAsRequired()
                            ->alignment(Alignment::Start)
                            ->width('58%'),
                        TableColumn::make('Code')
                            ->alignment(Alignment::Start)
                            ->width('10.5rem'),
                    ])
                    ->compact()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->hiddenLabel(),
                        TextInput::make('code')
                            ->placeholder('e.g. HR, FIN')
                            ->maxLength(20)
                            ->hiddenLabel(),
                    ])
                    ->reorderable(false)
                    ->deletable(true)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel('Add sub-office/department')
                    ->required()
                    ->visible(fn (string $operation): bool => self::isCreateOperation($operation)),
            ]);
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
