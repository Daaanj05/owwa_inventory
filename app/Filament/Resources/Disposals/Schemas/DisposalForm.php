<?php

namespace App\Filament\Resources\Disposals\Schemas;

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

class DisposalForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeCurrentFy = fn ($query) => $query->forFiscalYear(app(FiscalYearService::class)->current()?->id)->active();

        return $schema
            ->components([
                Section::make('Item & details')
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
                        Select::make('disposal_type')
                            ->label('Type of disposal')
                            ->options([
                                'waste_sale' => 'Waste or sale (WMR)',
                                'unserviceable' => 'Unserviceable (IIRUP / IIRUSP)',
                                'lost_stolen_damaged' => 'Lost, stolen, damaged or destroyed (RLSDDP)',
                            ])
                            ->placeholder('Select type'),
                        DatePicker::make('disposal_date')
                            ->label('Disposal date')
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
                        Select::make('office_id')
                            ->label('Office')
                            ->relationship('office', 'name', $scopeCurrentFy)
                            ->required()
                            ->searchable()
                            ->preload(),
                        TextInput::make('reason')
                            ->label('Reason')
                            ->placeholder('Why this item was disposed'),
                    ])
                    ->columns(2),

                Section::make('Property & cost')
                    ->schema([
                        TextInput::make('property_number')
                            ->label('Property number')
                            ->maxLength(255)
                            ->placeholder('Asset tag / property no.'),
                        TextInput::make('acquisition_cost')
                            ->label('Acquisition cost')
                            ->numeric()
                            ->prefix('₱')
                            ->minValue(0),
                        Textarea::make('remarks')
                            ->label('Remarks')
                            ->columnSpanFull()
                            ->rows(2)
                            ->placeholder('Optional notes'),
                    ])
                    ->columns(2),

                Section::make('Sale details')
                    ->description('Fill this in only if the items were sold.')
                    ->schema([
                        TextInput::make('official_receipt_no')
                            ->label('Official receipt number')
                            ->maxLength(255)
                            ->placeholder('—'),
                        DatePicker::make('sale_date')
                            ->label('Date of sale'),
                        TextInput::make('sale_amount')
                            ->label('Sale amount')
                            ->numeric()
                            ->prefix('₱')
                            ->minValue(0),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(),

                Section::make('Signatories')
                    ->schema([
                        TextInput::make('custodian_printed_name')
                            ->label('Custodian')
                            ->maxLength(255)
                            ->placeholder('Full name'),
                        TextInput::make('approved_by_printed_name')
                            ->label('Approved by')
                            ->maxLength(255)
                            ->placeholder('Full name'),
                        TextInput::make('inspection_officer_printed_name')
                            ->label('Inspection officer')
                            ->maxLength(255)
                            ->placeholder('Full name'),
                        TextInput::make('witness_printed_name')
                            ->label('Witness')
                            ->maxLength(255)
                            ->placeholder('Full name'),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}
