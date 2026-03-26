<?php

namespace App\Filament\Resources\Transfers;

use App\Filament\Resources\Transfers\Pages\ListTransfers;
use App\Filament\Resources\Transfers\Pages\ViewTransfer;
use App\Filament\Resources\Transfers\Schemas\TransferForm;
use App\Filament\Resources\Transfers\Tables\TransfersTable;
use App\Models\Transfer;
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

class TransferResource extends Resource
{
    protected static ?string $model = Transfer::class;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        app(FiscalYearService::class)->applyDateRangeFilter($query, 'transfer_date');

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return TransferForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transfer details')
                    ->schema([
                        TextEntry::make('reference_code')->label('Reference number'),
                        TextEntry::make('item.name')->label('Item'),
                        TextEntry::make('quantity')->label('Quantity'),
                        TextEntry::make('transfer_date')->label('Date')->date('M d, Y'),
                        TextEntry::make('fromOffice.name')->label('From office'),
                        TextEntry::make('toOffice.name')->label('To office'),
                        TextEntry::make('property_number')->label('Property number')->placeholder('—'),
                        TextEntry::make('condition')->label('Condition')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'Serviceable', 'Good' => 'success',
                                'Unserviceable' => 'danger',
                                'Poor' => 'warning',
                                default => 'gray',
                            })
                            ->placeholder('—'),
                        TextEntry::make('remarks')->label('Remarks')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Signatures')
                    ->schema([
                        TextEntry::make('approved_by_printed_name')->label('Approved by')->placeholder('—'),
                        TextEntry::make('released_by_printed_name')->label('Released by')->placeholder('—'),
                        TextEntry::make('received_by_printed_name')->label('Received by')->placeholder('—'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return TransfersTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->isSupplyCustodian();
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
            'index' => ListTransfers::route('/'),
            'view' => ViewTransfer::route('/{record}'),
        ];
    }
}
