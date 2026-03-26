<?php

namespace App\Filament\Resources\Issuances\Schemas;

use App\Models\Acquisition;
use App\Models\ItemCategory;
use App\Models\User;
use App\Services\FiscalYearService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class IssuanceForm
{
    public static function configure(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $isUnitHead = $user && $user->isAuthorizedPersonnel();
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
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                if (blank($state)) {
                                    $set('unit_cost', null);
                                    $set('amount', null);

                                    return;
                                }

                                $unitCost = Acquisition::query()
                                    ->where('item_id', $state)
                                    ->orderByDesc('acquisition_date')
                                    ->value('unit_cost');

                                $set('unit_cost', $unitCost !== null ? (float) $unitCost : null);

                                $quantity = (float) ($get('quantity') ?? 0);
                                if ($unitCost !== null && $quantity > 0) {
                                    $set('amount', $quantity * (float) $unitCost);
                                } else {
                                    $set('amount', null);
                                }
                            }),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->live(debounce: '500ms')
                            ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                $quantity = $state !== null ? (float) $state : 0;
                                $unitCost = (float) ($get('unit_cost') ?? 0);

                                $set('amount', $quantity > 0 && $unitCost > 0 ? round($quantity * $unitCost, 2) : null);
                            }),
                        TextInput::make('unit_cost')
                            ->label('Unit cost')
                            ->numeric()
                            ->prefix('₱')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Auto-filled from the latest acquisition.'),
                        TextInput::make('amount')
                            ->label('Total amount')
                            ->numeric()
                            ->prefix('₱')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Quantity × unit cost.'),
                        DatePicker::make('issuance_date')
                            ->label('Issuance date')
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
                    ])
                    ->columns(2),

                Section::make('Office & recipient')
                    ->schema([
                        Select::make('office_id')
                            ->label('Office')
                            ->relationship(
                                'office',
                                'name',
                                function (Builder $query) use ($scopeCurrentFy, $isUnitHead, $user) {
                                    $query = $scopeCurrentFy($query);
                                    if ($isUnitHead && $user->office_id) {
                                        $query->where('id', $user->office_id);
                                    }

                                    return $query;
                                }
                            )
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default($isUnitHead ? $user->office_id : null),
                        Select::make('department_id')
                            ->label('Department')
                            ->relationship(
                                'department',
                                'name',
                                function (Builder $query) use ($scopeCurrentFy, $isUnitHead, $user) {
                                    $query = $scopeCurrentFy($query);
                                    if ($isUnitHead && $user->office_id) {
                                        $query->where('office_id', $user->office_id);
                                    }

                                    return $query;
                                }
                            )
                            ->searchable()
                            ->preload()
                            ->placeholder('—'),
                        Select::make('issued_to')
                            ->label('Issued to')
                            ->relationship(
                                'issuedTo',
                                'name',
                                fn (Builder $query) => $isUnitHead && $user->office_id
                                    ? $query->where('office_id', $user->office_id)->where('role', User::ROLE_EMPLOYEE)
                                    : $query
                            )
                            ->searchable()
                            ->preload()
                            ->placeholder('—'),
                        Select::make('requisition_id')
                            ->label('Requisition')
                            ->relationship(
                                'requisition',
                                'reference_code',
                                fn (Builder $query) => $isUnitHead && $user->office_id
                                    ? $query->where('office_id', $user->office_id)
                                    : $query
                            )
                            ->searchable()
                            ->placeholder('—'),
                    ])
                    ->columns(2),

                Section::make('Additional details')
                    ->schema([
                        TextInput::make('property_number')
                            ->label('Property / inventory number')
                            ->maxLength(255)
                            ->placeholder('Unique asset tag'),
                        Textarea::make('remarks')
                            ->label('Remarks')
                            ->rows(2)
                            ->placeholder('Any notes'),
                    ])
                    ->columns(2),
            ]);
    }
}
