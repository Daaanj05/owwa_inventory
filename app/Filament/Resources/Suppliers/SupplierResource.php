<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 31;

    protected static ?string $navigationLabel = 'Suppliers';

    protected static ?string $modelLabel = 'Supplier';

    protected static ?string $pluralModelLabel = 'Suppliers';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Supplier name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('tin')
                    ->label('TIN')
                    ->rule('regex:/^[0-9]+$/')
                    ->extraInputAttributes(['inputmode' => 'numeric', 'pattern' => '[0-9]*'])
                    ->dehydrateStateUsing(fn (?string $state): ?string => Supplier::normalizeTin($state))
                    ->maxLength(20),
                Repeater::make('addresses')
                    ->relationship()
                    ->label('Addresses')
                    ->schema([
                        TextInput::make('address')
                            ->required()
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Toggle::make('is_default')
                            ->label('Default address')
                            ->default(false),
                    ])
                    ->defaultItems(1)
                    ->addActionLabel('Add address')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tin')
                    ->label('TIN')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('addresses_count')
                    ->counts('addresses')
                    ->label('Addresses'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Supplier $record): bool => ! $record->isArchived()),
                static::archiveAction(),
                static::restoreAction(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No suppliers yet')
            ->emptyStateDescription('Add suppliers with TIN and addresses for purchase orders.');
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Archive supplier?')
            ->modalDescription('Archived suppliers will no longer appear in purchase order suggestions until restored.')
            ->visible(fn (Supplier $record): bool => ! $record->isArchived())
            ->action(function (Supplier $record): void {
                $record->archive();

                Notification::make()
                    ->title('Supplier archived')
                    ->success()
                    ->send();
            });
    }

    public static function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Restore supplier?')
            ->modalDescription('This supplier will appear in purchase order suggestions again.')
            ->visible(fn (Supplier $record): bool => $record->isArchived())
            ->action(function (Supplier $record): void {
                $record->restoreFromArchive();

                Notification::make()
                    ->title('Supplier restored')
                    ->success()
                    ->send();
            });
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->isSupplyCustodian();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSuppliers::route('/'),
        ];
    }
}
