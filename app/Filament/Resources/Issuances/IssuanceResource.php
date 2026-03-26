<?php

namespace App\Filament\Resources\Issuances;

use App\Filament\Resources\Issuances\Pages\ListIssuances;
use App\Filament\Resources\Issuances\Pages\ViewIssuance;
use App\Filament\Resources\Issuances\Schemas\IssuanceForm;
use App\Filament\Resources\Issuances\Tables\IssuancesTable;
use App\Models\Issuance;
use App\Models\User;
use App\Services\FiscalYearService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class IssuanceResource extends Resource
{
    protected static ?string $model = Issuance::class;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Issuance';

    protected static ?string $pluralModelLabel = 'Issuances';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        app(FiscalYearService::class)->applyDateRangeFilter($query, 'issuance_date');
        $user = Filament::auth()->user();
        if ($user && $user->isAuthorizedPersonnel() && $user->office_id) {
            $query->where('office_id', $user->office_id);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return IssuanceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transaction')
                    ->schema([
                        TextEntry::make('reference_code')->label('Reference number'),
                        TextEntry::make('issuance_date')->label('Date')->date('M d, Y'),
                        TextEntry::make('office.name')->label('Office'),
                        TextEntry::make('department.name')->label('Department')->placeholder('—'),
                        TextEntry::make('item.name')->label('Item'),
                        TextEntry::make('quantity')->label('Quantity'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Pricing')
                    ->schema([
                        TextEntry::make('unit_cost')->label('Unit cost')->money('PHP'),
                        TextEntry::make('amount')->label('Amount')->money('PHP'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Assignment')
                    ->schema([
                        TextEntry::make('requisition.reference_code')->label('Requisition')->placeholder('—'),
                        TextEntry::make('issuedTo.name')->label('Issued to')->placeholder('—'),
                        TextEntry::make('property_number')->label('Property / inventory number')->placeholder('—'),
                        TextEntry::make('remarks')->label('Remarks')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return IssuancesTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user && ($user->isSupplyCustodian() || $user->isAuthorizedPersonnel());
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIssuances::route('/'),
            'view' => ViewIssuance::route('/{record}'),
        ];
    }
}
