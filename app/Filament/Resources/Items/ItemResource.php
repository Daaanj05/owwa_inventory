<?php

namespace App\Filament\Resources\Items;

use App\Filament\Concerns\HasOwwaViewModalUrl;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Items\Schemas\ItemForm;
use App\Filament\Resources\Items\Schemas\ItemInfolist;
use App\Filament\Resources\Items\Tables\ItemsTable;
use App\Models\Item;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ItemResource extends Resource
{
    use HasOwwaViewModalUrl;

    protected static ?string $model = Item::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Item';

    protected static ?string $pluralModelLabel = 'Items';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->active();

        $categoryId = session('active_item_category_id');
        if (filled($categoryId)) {
            $query->where('item_category_id', (int) $categoryId);
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return ItemForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ItemInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ItemsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->isSupplyCustodian();
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
            'index' => ListItems::route('/'),
        ];
    }
}
