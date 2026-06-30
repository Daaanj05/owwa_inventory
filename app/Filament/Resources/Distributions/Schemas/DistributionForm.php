<?php

namespace App\Filament\Resources\Distributions\Schemas;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DistributionForm
{
    public static function configure(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        return $schema
            ->columns(1)
            ->components([
                Section::make('Distribution details')
                    ->description('Record items distributed to a Employee.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('item_id')
                            ->label('Item')
                            ->relationship('item', 'name', fn ($query) => $query->active())
                            ->required()
                            ->searchable()
                            ->preload(),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->numeric()
                            ->minValue(1),
                        Select::make('distributed_to')
                            ->label('Distribute to (Employee)')
                            ->options(function () use ($user): array {
                                $query = User::query()->where('role', User::ROLE_EMPLOYEE);

                                if ($user?->office_id) {
                                    $query->where('office_id', $user->office_id);
                                }

                                if ($user?->department_id) {
                                    $query->where('department_id', $user->department_id);
                                }

                                return $query->orderBy('name')->pluck('name', 'id')->toArray();
                            })
                            ->required()
                            ->searchable(),
                        DatePicker::make('distribution_date')
                            ->label('Date')
                            ->required()
                            ->default(now()),
                        Textarea::make('remarks')
                            ->columnSpanFull()
                            ->rows(2),
                    ]),
            ]);
    }
}
