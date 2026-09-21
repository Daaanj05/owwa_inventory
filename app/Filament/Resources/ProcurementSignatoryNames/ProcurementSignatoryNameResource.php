<?php

namespace App\Filament\Resources\ProcurementSignatoryNames;

use App\Filament\Resources\ProcurementSignatoryNames\Pages\ManageProcurementSignatoryNames;
use App\Models\ProcurementSignatoryName;
use App\Support\SignatorySelect;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ProcurementSignatoryNameResource extends Resource
{
    protected static ?string $model = ProcurementSignatoryName::class;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Signatories';

    protected static ?string $modelLabel = 'Signatory';

    protected static ?string $pluralModelLabel = 'Signatories';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('role')
                    ->label('Role')
                    ->options(SignatorySelect::roleOptions())
                    ->required()
                    ->searchable(),
                TextInput::make('name')
                    ->label('Printed name / designation')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        $roleOptions = SignatorySelect::roleOptions();

        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->label('Role')
                    ->formatStateUsing(fn (string $state): string => $roleOptions[$state] ?? $state)
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('archived_at')
                    ->label('Archived')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('role')
                    ->label('Role')
                    ->options($roleOptions),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (ProcurementSignatoryName $record): bool => ! $record->isArchived()),
                self::archiveAction(),
                self::restoreAction(),
            ])
            ->emptyStateHeading('No signatories yet')
            ->emptyStateDescription('Add printed names and designations used on procurement and inventory forms.');
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Archive signatory?')
            ->modalDescription('Archived names will no longer appear in form suggestions until restored.')
            ->visible(fn (ProcurementSignatoryName $record): bool => ! $record->isArchived())
            ->action(function (ProcurementSignatoryName $record): void {
                $record->archive();

                Notification::make()
                    ->title('Signatory archived')
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
            ->modalHeading('Restore signatory?')
            ->modalDescription('This name will appear in form suggestions again.')
            ->visible(fn (ProcurementSignatoryName $record): bool => $record->isArchived())
            ->action(function (ProcurementSignatoryName $record): void {
                $record->restoreFromArchive();

                Notification::make()
                    ->title('Signatory restored')
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
            'index' => ManageProcurementSignatoryNames::route('/'),
        ];
    }
}
