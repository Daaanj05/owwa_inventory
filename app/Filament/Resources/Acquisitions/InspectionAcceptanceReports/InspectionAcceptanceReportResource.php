<?php

namespace App\Filament\Resources\Acquisitions\InspectionAcceptanceReports;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Pages\ListInspectionAcceptanceReports;
use App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Schemas\InspectionAcceptanceReportForm;
use App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Schemas\InspectionAcceptanceReportInfolist;
use App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Tables\InspectionAcceptanceReportsTable;
use App\Models\InspectionAcceptanceReport;
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

class InspectionAcceptanceReportResource extends Resource
{
    protected static ?string $model = InspectionAcceptanceReport::class;

    protected static ?string $slug = 'inspection-acceptance-reports';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Regional supply';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $modelLabel = 'Inspection & acceptance report';

    protected static ?string $pluralModelLabel = 'Inspection & acceptance reports';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['purchaseOrder.purchaseRequest.itemCategory', 'lines'])
            ->whereHas('purchaseOrder.purchaseRequest', function (Builder $builder): void {
                CustodianOfficeScope::applyOfficeColumn($builder);
                $categoryId = SyncsActiveItemCategory::resolveCategoryIdFromContext();
                if ($categoryId > 0) {
                    $builder->where('item_category_id', $categoryId);
                } else {
                    $builder->whereRaw('1 = 0');
                }
            });
    }

    public static function form(Schema $schema): Schema
    {
        return InspectionAcceptanceReportForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InspectionAcceptanceReportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InspectionAcceptanceReportsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->isSupplyCustodian();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInspectionAcceptanceReports::route('/'),
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
        $tableAction = $model instanceof InspectionAcceptanceReport && $model->isEditable() ? 'edit' : 'view';

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
