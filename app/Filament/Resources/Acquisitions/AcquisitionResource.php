<?php

namespace App\Filament\Resources\Acquisitions;

use App\Filament\Resources\Acquisitions\Pages\ListAcquisitions;
use App\Filament\Resources\Acquisitions\Pages\ViewAcquisition;
use App\Filament\Resources\Acquisitions\Schemas\AcquisitionForm;
use App\Filament\Resources\Acquisitions\Tables\AcquisitionsTable;
use App\Models\Acquisition;
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

class AcquisitionResource extends Resource
{
    protected static ?string $model = Acquisition::class;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Acquisition';

    protected static ?string $pluralModelLabel = 'Acquisitions';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        app(FiscalYearService::class)->applyDateRangeFilter($query, 'acquisition_date');

        $categoryId = session('active_item_category_id');
        if (filled($categoryId)) {
            $query->whereHas('item', function (Builder $itemQuery) use ($categoryId): void {
                $itemQuery->where('item_category_id', (int) $categoryId);
            });
        } else {
            // Don't show acquisitions until the user selects a category.
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return AcquisitionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Acquisition details')
                    ->schema([
                        TextEntry::make('reference_code')->label('Reference number'),
                        TextEntry::make('item.name')->label('Item'),
                        TextEntry::make('quantity')->label('Quantity'),
                        TextEntry::make('unit_cost')->label('Unit cost')->money('PHP'),
                        TextEntry::make('acquisition_date')->label('Date')->date('M d, Y'),
                        TextEntry::make('source')->label('Source')->placeholder('—'),
                        TextEntry::make('remarks')->label('Remarks')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return AcquisitionsTable::configure($table);
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
            'index' => ListAcquisitions::route('/'),
            'view' => ViewAcquisition::route('/{record}'),
        ];
    }
}
