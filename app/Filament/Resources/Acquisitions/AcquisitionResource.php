<?php

namespace App\Filament\Resources\Acquisitions;

use App\Filament\Concerns\HasOwwaViewModalUrl;
use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Acquisitions\Pages\ListAcquisitions;
use App\Filament\Resources\Acquisitions\Pages\ViewAcquisition;
use App\Filament\Resources\Acquisitions\Paperwork\Schemas\AcquisitionPaperworkForm;
use App\Filament\Resources\Acquisitions\Paperwork\Schemas\AcquisitionPaperworkInfolist;
use App\Filament\Resources\Acquisitions\Tables\AcquisitionsTable;
use App\Models\AcquisitionPaperwork;
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

class AcquisitionResource extends Resource
{
    use HasOwwaViewModalUrl;

    protected static ?string $model = AcquisitionPaperwork::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Regional supply';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Acquisition';

    protected static ?string $pluralModelLabel = 'Acquisitions';

    public static function getEloquentQuery(): Builder
    {
        $query = CustodianOfficeScope::applyOfficeColumn(parent::getEloquentQuery());

        $categoryId = SyncsActiveItemCategory::resolveCategoryIdFromContext();
        if ($categoryId > 0) {
            $query->where('item_category_id', $categoryId);
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return AcquisitionPaperworkForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AcquisitionPaperworkInfolist::configure($schema);
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

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAcquisitions::route('/'),
            'view' => ViewAcquisition::route('/{record}'),
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

        $tableAction = $model instanceof AcquisitionPaperwork && $model->isReceived()
            ? 'view'
            : 'edit';

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
