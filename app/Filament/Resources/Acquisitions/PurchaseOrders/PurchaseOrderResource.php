<?php

namespace App\Filament\Resources\Acquisitions\PurchaseOrders;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Acquisitions\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\Acquisitions\PurchaseOrders\Schemas\PurchaseOrderForm;
use App\Filament\Resources\Acquisitions\PurchaseOrders\Schemas\PurchaseOrderInfolist;
use App\Filament\Resources\Acquisitions\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Models\PurchaseOrder;
use App\Support\CustodianOfficeScope;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $slug = 'purchase-orders';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Regional supply';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $modelLabel = 'Purchase order';

    protected static ?string $pluralModelLabel = 'Purchase orders';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['purchaseRequest.itemCategory', 'supplier', 'lines'])
            ->whereHas('purchaseRequest', function (Builder $builder): void {
                CustodianOfficeScope::applyOfficeColumn($builder);
                $categoryId = SyncsActiveItemCategory::resolveCategoryIdFromContext();
                if ($categoryId > 0) {
                    $builder->where('item_category_id', $categoryId);
                } else {
                    $builder->whereRaw('1 = 0');
                }
            });

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PurchaseOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseOrdersTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->isSupplyCustodian();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
        ];
    }

    /**
     * @param  array<string, mixed>  $extraParams
     */
    public static function viewModalUrl(Model|int $record, array $extraParams = []): string
    {
        $model = $record instanceof Model
            ? $record
            : static::getModel()::query()->find($record);

        $id = $model instanceof Model ? $model->getKey() : $record;
        $tableAction = $model instanceof PurchaseOrder && $model->isEditable() ? 'edit' : 'view';

        $params = array_merge([
            'tableAction' => $tableAction,
            'tableActionRecord' => $id,
        ], $extraParams);

        if ($categoryId = SyncsActiveItemCategory::resolveCategoryIdFromContext()) {
            $params['category'] ??= $categoryId;
        }

        return static::getUrl('index', $params);
    }
}
