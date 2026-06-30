<?php

namespace App\Filament\Resources\PhysicalInventoryPlans;

use App\Filament\Concerns\HasOwwaViewModalUrl;
use App\Filament\Resources\PhysicalInventoryPlans\Pages\CreatePhysicalInventoryPlan;
use App\Filament\Resources\PhysicalInventoryPlans\Pages\EditPhysicalInventoryPlan;
use App\Filament\Resources\PhysicalInventoryPlans\Pages\ListPhysicalInventoryPlans;
use App\Filament\Resources\PhysicalInventoryPlans\Pages\ViewPhysicalInventoryPlan;
use App\Filament\Resources\PhysicalInventoryPlans\RelationManagers\LinesRelationManager;
use App\Filament\Resources\PhysicalInventoryPlans\Schemas\PhysicalInventoryPlanForm;
use App\Filament\Resources\PhysicalInventoryPlans\Schemas\PhysicalInventoryPlanInfolist;
use App\Filament\Resources\PhysicalInventoryPlans\Tables\PhysicalInventoryPlansTable;
use App\Models\PhysicalInventoryPlan;
use App\Models\User;
use App\Services\InventoryPlanCategoryQuery;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class PhysicalInventoryPlanResource extends Resource
{
    use HasOwwaViewModalUrl;

    protected static ?string $model = PhysicalInventoryPlan::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $modelLabel = 'Inventory Schedule';

    protected static ?string $pluralModelLabel = 'Inventory Schedules';

    public static function getEloquentQuery(): Builder
    {
        return InventoryPlanCategoryQuery::apply(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return PhysicalInventoryPlanForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PhysicalInventoryPlanInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PhysicalInventoryPlansTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isSupplyCustodian();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPhysicalInventoryPlans::route('/'),
            'create' => CreatePhysicalInventoryPlan::route('/create'),
            'view' => ViewPhysicalInventoryPlan::route('/{record}'),
            'edit' => EditPhysicalInventoryPlan::route('/{record}/edit'),
        ];
    }
}
