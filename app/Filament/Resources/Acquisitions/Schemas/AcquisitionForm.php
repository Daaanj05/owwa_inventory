<?php

namespace App\Filament\Resources\Acquisitions\Schemas;

use App\Models\ItemCategory;
use App\Services\FiscalYearService;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class AcquisitionForm
{
    public static function configure(Schema $schema): Schema
    {
        $scopeCurrentFy = fn ($query) => $query->forFiscalYear(app(FiscalYearService::class)->current()?->id)->active();

        return $schema
            ->components([
                Section::make('Acquisition details')
                    ->description('Record incoming stock received by the office.')
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
                            ->live()
                            ->dehydrated(false)
                            ->default(fn (): mixed => session('active_item_category_id'))
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
                        TextInput::make('unit_cost')
                            ->label('Unit cost')
                            ->numeric()
                            ->prefix('₱')
                            ->minValue(0)
                            ->helperText('Cost per unit — used to auto-fill issuance pricing.'),
                        DatePicker::make('acquisition_date')
                            ->label('Date')
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
                        TextInput::make('source')
                            ->label('Source')
                            ->placeholder('e.g. Supplier name, procurement reference')
                            ->maxLength(255),
                        Textarea::make('remarks')
                            ->label('Remarks')
                            ->columnSpanFull()
                            ->rows(2)
                            ->placeholder('Any notes'),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }
}
