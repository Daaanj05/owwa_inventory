<?php

namespace App\Filament\Resources\DeliveryTerms;

use App\Filament\Resources\DeliveryTerms\Pages\ManageDeliveryTerms;
use App\Models\DeliveryTerm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class DeliveryTermResource extends Resource
{
    protected static ?string $model = DeliveryTerm::class;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 32;

    protected static ?string $navigationLabel = 'Delivery terms';

    protected static ?string $modelLabel = 'Delivery term';

    protected static ?string $pluralModelLabel = 'Delivery terms';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('label')
                    ->label('Delivery term')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->placeholder('e.g. FOB Destination'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Delivery term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('label')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (DeliveryTerm $record): bool => ! $record->isArchived()),
                static::archiveAction(),
                static::restoreAction(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No delivery terms yet')
            ->emptyStateDescription('Add terms such as FOB Destination for purchase orders. These are not stored on suppliers.');
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Archive delivery term?')
            ->modalDescription('Archived terms will no longer appear in purchase order suggestions until restored.')
            ->visible(fn (DeliveryTerm $record): bool => ! $record->isArchived())
            ->action(function (DeliveryTerm $record): void {
                $record->archive();

                Notification::make()
                    ->title('Delivery term archived')
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
            ->modalHeading('Restore delivery term?')
            ->modalDescription('This term will appear in purchase order suggestions again.')
            ->visible(fn (DeliveryTerm $record): bool => $record->isArchived())
            ->action(function (DeliveryTerm $record): void {
                $record->restoreFromArchive();

                Notification::make()
                    ->title('Delivery term restored')
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
            'index' => ManageDeliveryTerms::route('/'),
        ];
    }
}
