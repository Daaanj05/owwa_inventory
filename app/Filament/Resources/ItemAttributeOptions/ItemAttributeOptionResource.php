<?php

namespace App\Filament\Resources\ItemAttributeOptions;

use App\Filament\Resources\ItemAttributeOptions\Pages\ManageItemAttributeOptions;
use App\Models\ItemAttributeOption;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class ItemAttributeOptionResource extends Resource
{
    protected static ?string $model = ItemAttributeOption::class;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'Item attribute lists';

    protected static ?string $modelLabel = 'Item attribute';

    protected static ?string $pluralModelLabel = 'Item attribute lists';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('kind')
                    ->label('Kind')
                    ->options(ItemAttributeOption::kindOptions())
                    ->required()
                    ->native(false),
                TextInput::make('value')
                    ->label('Value (code)')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Stored on the item (e.g. office_equipment). For units, same as label.'),
                TextInput::make('label')
                    ->label('Label')
                    ->required()
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        $kinds = ItemAttributeOption::kindOptions();

        return $table
            ->columns([
                TextColumn::make('kind')
                    ->formatStateUsing(fn (string $state): string => $kinds[$state] ?? $state)
                    ->badge()
                    ->sortable(),
                TextColumn::make('label')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('value')
                    ->label('Code')
                    ->searchable()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->defaultSort('kind')
            ->filters([
                SelectFilter::make('kind')
                    ->options($kinds),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No attribute options yet')
            ->emptyStateDescription('Seed measurement units, inventory types, property classes, and PPE types for item forms.');
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->isSystemAdmin();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageItemAttributeOptions::route('/'),
        ];
    }
}
