<?php

namespace App\Filament\Resources\ReferenceSeries\Schemas;

use App\Models\ReferenceSeries;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ReferenceSeriesForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Reference number format')
                    ->description('Transaction control numbers are assigned automatically when Supply Custodian creates a record. For issuances, transfers, requisitions, disposals, and acquisitions the system outputs OWWA-style **YYYY-MM-####** (e.g. 2026-01-0001). Export filenames add the form label at download time (RIS, RSMI, PTR). Code letters for stock/property series (CON, PPE) still appear in generated stock numbers.')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('type')
                            ->label('Series key')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Fixed for this row; cannot be changed.'),
                        TextInput::make('name')
                            ->label('Label in admin')
                            ->maxLength(100)
                            ->helperText('Describes which OWWA form label this series feeds (e.g. Appendix 63 RIS vs RSMI Serial No.).'),
                        TextInput::make('prefix')
                            ->label('Code letters (reference)')
                            ->required(fn (Get $get): bool => ! self::isTransactionSeriesType((string) $get('type')))
                            ->visible(fn (Get $get): bool => ! self::isTransactionSeriesType((string) $get('type')))
                            ->maxLength(20)
                            ->helperText('Used for stock/property series (CON, PPE, SE). Required for item and property number formats.'),
                        TextInput::make('pattern')
                            ->label('Format (advanced)')
                            ->required()
                            ->maxLength(100)
                            ->helperText('Transaction types: keep default; output is normalized to YYYY-MM-####. Item/property types: CON-{Y}-{seq:4}, etc.'),
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

    public static function isTransactionSeriesType(string $type): bool
    {
        return in_array($type, ReferenceSeries::transactionSeriesTypes(), true);
    }
}
