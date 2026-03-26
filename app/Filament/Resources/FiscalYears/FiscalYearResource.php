<?php

namespace App\Filament\Resources\FiscalYears;

use App\Filament\Resources\FiscalYears\Pages\CreateFiscalYear;
use App\Filament\Resources\FiscalYears\Pages\EditFiscalYear;
use App\Filament\Resources\FiscalYears\Pages\ListFiscalYears;
use App\Models\FiscalYear;
use App\Models\Department;
use App\Models\Item;
use App\Models\Office;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class FiscalYearResource extends Resource
{
    protected static ?string $model = FiscalYear::class;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Fiscal year')
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->helperText('Example: 2024, 2024–2025')
                            ->required()
                            ->maxLength(50),
                        DatePicker::make('start_date')
                            ->label('Start date')
                            ->required(),
                        DatePicker::make('end_date')
                            ->label('End date')
                            ->required()
                            ->after('start_date'),
                        Toggle::make('is_default')
                            ->label('Default year')
                            ->helperText('Used when no active fiscal year is selected.'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('name')
                    ->label('Fiscal year')
                    ->sortable()
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('start_date')
                    ->date('M d, Y')
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('end_date')
                    ->date('M d, Y')
                    ->sortable(),
                \Filament\Tables\Columns\IconColumn::make('is_default')
                    ->boolean()
                    ->label('Default'),
            ])
            ->defaultSort('start_date', 'desc')
            ->recordActions([
                EditAction::make(),
                Action::make('copySetup')
                    ->label('Copy setup')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Copy setup from previous fiscal year')
                    ->modalDescription('Copies ACTIVE offices, departments, and items from the previous fiscal year into this fiscal year. Existing setup records in the target year are not modified.')
                    ->action(function (FiscalYear $record): void {
                        $source = FiscalYear::query()
                            ->whereKeyNot($record->id)
                            ->where('start_date', '<', $record->start_date)
                            ->orderByDesc('start_date')
                            ->first();

                        if (! $source) {
                            Notification::make()
                                ->title('No previous fiscal year found')
                                ->body('Create an older fiscal year first, or copy manually.')
                                ->danger()
                                ->send();
                            return;
                        }

                        $targetHasSetup = Office::query()->where('fiscal_year_id', $record->id)->exists()
                            || Department::query()->where('fiscal_year_id', $record->id)->exists()
                            || Item::query()->where('fiscal_year_id', $record->id)->exists();

                        if ($targetHasSetup) {
                            Notification::make()
                                ->title('Target fiscal year already has setup data')
                                ->body('To avoid duplicates, copying is blocked when offices, departments, or items already exist for that year.')
                                ->warning()
                                ->send();
                            return;
                        }

                        DB::transaction(function () use ($source, $record): void {
                            $officeMap = [];

                            $sourceOffices = Office::query()
                                ->where('fiscal_year_id', $source->id)
                                ->whereNull('archived_at')
                                ->get();

                            foreach ($sourceOffices as $office) {
                                $new = $office->replicate([
                                    'fiscal_year_id',
                                    'archived_at',
                                    'created_at',
                                    'updated_at',
                                ]);
                                $new->fiscal_year_id = $record->id;
                                $new->archived_at = null;
                                $new->save();

                                $officeMap[$office->id] = $new->id;
                            }

                            $sourceDepartments = Department::query()
                                ->where('fiscal_year_id', $source->id)
                                ->whereNull('archived_at')
                                ->get();

                            foreach ($sourceDepartments as $department) {
                                $newOfficeId = $officeMap[$department->office_id] ?? null;
                                if (! $newOfficeId) {
                                    continue;
                                }

                                $new = $department->replicate([
                                    'fiscal_year_id',
                                    'office_id',
                                    'archived_at',
                                    'created_at',
                                    'updated_at',
                                ]);
                                $new->fiscal_year_id = $record->id;
                                $new->office_id = $newOfficeId;
                                $new->archived_at = null;
                                $new->save();
                            }

                            $sourceItems = Item::query()
                                ->where('fiscal_year_id', $source->id)
                                ->whereNull('archived_at')
                                ->get();

                            foreach ($sourceItems as $item) {
                                $new = $item->replicate([
                                    'fiscal_year_id',
                                    'archived_at',
                                    'created_at',
                                    'updated_at',
                                ]);
                                $new->fiscal_year_id = $record->id;
                                $new->archived_at = null;
                                $new->save();
                            }
                        });

                        Notification::make()
                            ->title('Setup copied')
                            ->body("Copied setup from {$source->name} to {$record->name}.")
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSystemAdmin() ?? false;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFiscalYears::route('/'),
            'create' => CreateFiscalYear::route('/create'),
            'edit' => EditFiscalYear::route('/{record}/edit'),
        ];
    }
}

