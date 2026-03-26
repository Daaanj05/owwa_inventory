<?php

namespace App\Filament\Resources\Requisitions\Schemas;

use App\Models\Requisition;
use App\Services\FiscalYearService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RequisitionForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeCurrentFy = fn ($query) => $query->forFiscalYear(app(FiscalYearService::class)->current()?->id)->active();

        return $schema
            ->components([
                Section::make('Requisition details')
                    ->description('Submit a request for inventory items from a department or office.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('reference_code')
                            ->label('Reference code')
                            ->disabled()
                            ->visible(fn (string $operation): bool => $operation === 'edit')
                            ->columnSpanFull(),
                        Select::make('office_id')
                            ->label('Office')
                            ->relationship('office', 'name', $scopeCurrentFy)
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('department_id')
                            ->label('Department')
                            ->relationship('department', 'name', $scopeCurrentFy)
                            ->searchable()
                            ->preload()
                            ->placeholder('None'),
                        Select::make('status')
                            ->label('Status')
                            ->options([
                                Requisition::STATUS_PENDING   => 'Pending',
                                Requisition::STATUS_APPROVED  => 'Approved',
                                Requisition::STATUS_REJECTED  => 'Rejected',
                                Requisition::STATUS_FULFILLED => 'Fulfilled',
                            ])
                            ->default(Requisition::STATUS_PENDING)
                            ->required()
                            ->visible(fn (): bool => Filament::auth()->user()?->isSupplyCustodian() ?? false),
                        Textarea::make('remarks')
                            ->columnSpanFull()
                            ->rows(3),
                    ]),
            ]);
    }
}
