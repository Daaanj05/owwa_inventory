<?php

namespace App\Filament\Resources\ItemAttributeOptions;

use App\Filament\Resources\ItemAttributeOptions\Pages\ManageItemAttributeOptions;
use App\Models\ItemAttributeOption;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
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
            ->columns(1)
            ->components([
                Select::make('kind')
                    ->label('Classification')
                    ->options(ItemAttributeOption::kindOptions())
                    ->placeholder('Select a classification')
                    ->helperText('Choose the list this value belongs to.')
                    ->required()
                    ->native(false),
                TextInput::make('value')
                    ->label('Value')
                    ->placeholder('office_supplies')
                    ->helperText('Short code stored on the item. For a measurement unit, use the same text as the label.')
                    ->required()
                    ->maxLength(255),
                TextInput::make('label')
                    ->label('Label')
                    ->placeholder('Office Supplies Inventory')
                    ->helperText('Name shown on item forms.')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('value')
                    ->label('Value')
                    ->searchable(),
            ])
            ->defaultSort('label')
            ->recordActions([
                EditAction::make(),
                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->iconButton()
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Archive item attribute')
                    ->modalDescription('This value stays on the list and can be restored. Item forms will stop offering it.')
                    ->visible(fn (ItemAttributeOption $record): bool => $record->is_active)
                    ->action(fn (ItemAttributeOption $record): bool => $record->update(['is_active' => false])),
                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->iconButton()
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Restore item attribute')
                    ->modalDescription('Item forms will offer this value again.')
                    ->visible(fn (ItemAttributeOption $record): bool => ! $record->is_active)
                    ->action(fn (ItemAttributeOption $record): bool => $record->update(['is_active' => true])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('archive')
                        ->label('Archive selected')
                        ->icon('heroicon-o-archive-box')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function ($records): void {
                            $records->each->update(['is_active' => false]);
                        }),
                ]),
            ])
            ->emptyStateHeading('No attribute options yet')
            ->emptyStateDescription('Seed measurement units, inventory types, property classes, and PPE types for item forms.');
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->isSupplyCustodian();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageItemAttributeOptions::route('/'),
        ];
    }
}
