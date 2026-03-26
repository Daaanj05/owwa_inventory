<?php

namespace App\Filament\Resources\Transfers\Schemas;

use App\Models\ItemCategory;
use App\Services\FiscalYearService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class TransferForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeCurrentFy = fn ($query) => $query->forFiscalYear(app(FiscalYearService::class)->current()?->id)->active();

        return $schema
            ->components([
                Section::make('Item & quantity')
                    ->schema([
                        TextInput::make('reference_code')
                            ->label('Reference number')
                            ->disabled()
                            ->visible(fn (string $operation): bool => $operation === 'edit')
                            ->columnSpanFull(),
                        Select::make('item_category_filter')
                            ->label('Category')
                            ->options(fn (): array => cache()->remember(
                                'item_categories.options',
                                3600,
                                fn (): array => ItemCategory::query()->orderBy('name')->pluck('name', 'id')->toArray()
                            ))
                            ->placeholder('All categories')
                            ->default(fn (): mixed => session('active_item_category_id'))
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(fn (Set $set) => $set('item_id', null)),
                        Select::make('item_id')
                            ->label('Item')
                            ->relationship(
                                'item',
                                'name',
                                function (Builder $query, Get $get) use ($scopeCurrentFy) {
                                    $query = $scopeCurrentFy($query);
                                    $categoryId = $get('item_category_filter');
                                    if (filled($categoryId)) {
                                        $query->where('item_category_id', $categoryId);
                                    }

                                    return $query;
                                }
                            )
                            ->required()
                            ->searchable()
                            ->preload(),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->numeric()
                            ->minValue(1),
                        DatePicker::make('transfer_date')
                            ->label('Transfer date')
                            ->required()
                            ->rule(function () {
                                return function (string $attribute, $value, \Closure $fail) {
                                    $message = app(\App\Services\FiscalYearService::class)
                                        ->validateDateInCurrentYear($value);
                                    if ($message) {
                                        $fail($message);
                                    }
                                };
                            }),
                        Select::make('condition')
                            ->label('Condition of property')
                            ->options([
                                'Serviceable' => 'Serviceable',
                                'Unserviceable' => 'Unserviceable',
                                'Good' => 'Good',
                                'Poor' => 'Poor',
                            ])
                            ->placeholder('Select condition'),
                    ])
                    ->columns(2),

                Section::make('Offices')
                    ->schema([
                        Select::make('from_office_id')
                            ->label('From office')
                            ->relationship('fromOffice', 'name', $scopeCurrentFy)
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('to_office_id')
                            ->label('To office')
                            ->relationship('toOffice', 'name', $scopeCurrentFy)
                            ->required()
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(2),

                Section::make('Additional details')
                    ->schema([
                        TextInput::make('property_number')
                            ->label('Property number')
                            ->maxLength(255)
                            ->placeholder('Asset tag / property no.'),
                        Textarea::make('remarks')
                            ->label('Remarks')
                            ->rows(2)
                            ->placeholder('Optional notes'),
                    ])
                    ->columns(2),

                Section::make('Signatories')
                    ->schema([
                        TextInput::make('approved_by_printed_name')
                            ->label('Approved by')
                            ->maxLength(255)
                            ->placeholder('Full name'),
                        TextInput::make('released_by_printed_name')
                            ->label('Released by')
                            ->maxLength(255)
                            ->placeholder('Full name'),
                        TextInput::make('received_by_printed_name')
                            ->label('Received by')
                            ->maxLength(255)
                            ->placeholder('Full name'),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}
