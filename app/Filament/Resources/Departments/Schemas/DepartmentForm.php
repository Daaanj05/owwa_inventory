<?php

namespace App\Filament\Resources\Departments\Schemas;

use App\Rules\UniqueDepartmentNameInOffice;
use App\Services\FiscalYearService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DepartmentForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeCurrentFy = fn ($query) => $query->forFiscalYear(app(FiscalYearService::class)->current()?->id)->active();

        return $schema
            ->components([
                Section::make('Department details')
                    ->description('Departments belong to an OWWA office.')
                    ->columns(2)
                    ->schema([
                        Select::make('office_id')
                            ->label('Office')
                            ->relationship('office', 'name', $scopeCurrentFy)
                            ->required()
                            ->searchable()
                            ->preload()
                            ->columnSpanFull(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->rules([new UniqueDepartmentNameInOffice]),
                        TextInput::make('code')
                            ->label('Code')
                            ->placeholder('e.g. HR, FIN')
                            ->maxLength(20),
                    ]),
            ]);
    }
}
