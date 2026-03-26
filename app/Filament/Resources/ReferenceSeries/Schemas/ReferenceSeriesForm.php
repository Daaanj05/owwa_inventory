<?php

namespace App\Filament\Resources\ReferenceSeries\Schemas;

use App\Models\ReferenceSeries;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReferenceSeriesForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Reference number format')
                    ->description('Each new record gets a reference number like **RIS-2026-0001**. You choose the letters (code), and the system adds the year and the next number. You only need to change the "Format (advanced)" box if your office uses a different style.')
                    ->schema([
                        TextInput::make('type')
                            ->label('Transaction type')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Fixed for this row; cannot be changed.'),
                        TextInput::make('name')
                            ->label('Label for reports')
                            ->maxLength(100)
                            ->helperText('Optional. Shown in reports and exports.'),
                        TextInput::make('prefix')
                            ->label('Code letters')
                            ->required()
                            ->maxLength(20)
                            ->helperText('The letters at the start of every number (e.g. RIS, PTR).'),
                        TextInput::make('pattern')
                            ->label('Format (advanced)')
                            ->required()
                            ->maxLength(100)
                            ->helperText('Leave as is unless you need a different style. It means: code letters, then year, then a 4-digit number.'),
                        TextInput::make('next_sequence')
                            ->label('Next number to use')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->helperText('The system increases this automatically. You can set it here if you need to start from a specific number.'),
                        Select::make('reset_period')
                            ->label('When to start counting from 1 again')
                            ->options([
                                ReferenceSeries::RESET_NONE => 'Never',
                                ReferenceSeries::RESET_DAILY => 'Every day',
                                ReferenceSeries::RESET_MONTHLY => 'Every month',
                                ReferenceSeries::RESET_YEARLY => 'Every year',
                            ])
                            ->required()
                            ->default(ReferenceSeries::RESET_YEARLY)
                            ->helperText('"Every year" is usual: each year you get 0001, 0002, … again.'),
                        TextInput::make('last_generated_at')
                            ->label('Last used on')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn ($state) => $state instanceof \DateTimeInterface ? $state->format('M j, Y') : ($state ? (string) $state : '—')),
                    ])
                    ->columns(2)
                    ->compact(),
            ]);
    }
}
