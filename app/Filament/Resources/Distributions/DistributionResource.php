<?php

namespace App\Filament\Resources\Distributions;

use App\Filament\Concerns\HasOwwaViewModalUrl;
use App\Filament\Resources\Distributions\Pages\ListDistributions;
use App\Filament\Resources\Distributions\Schemas\DistributionForm;
use App\Filament\Resources\Distributions\Schemas\DistributionInfolist;
use App\Filament\Resources\Distributions\Tables\DistributionsTable;
use App\Models\Distribution;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DistributionResource extends Resource
{
    use HasOwwaViewModalUrl;

    protected static ?string $model = Distribution::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Requisitions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Distribution';

    protected static ?string $pluralModelLabel = 'Distributions';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = Filament::auth()->user();
        $categoryId = session('active_item_category_id');

        if ($user instanceof User && $user->isUnitConsolidator()) {
            if ($user->office_id) {
                $query->where('office_id', (int) $user->office_id);
            }
            if ($user->department_id) {
                $query->where('department_id', (int) $user->department_id);
            }
        } else {
            $query->whereRaw('1 = 0');
        }

        if (filled($categoryId)) {
            $query->whereHas('item', function (Builder $itemQuery) use ($categoryId): void {
                $itemQuery->where('item_category_id', (int) $categoryId);
            });
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return DistributionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DistributionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DistributionsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isUnitConsolidator();
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isUnitConsolidator();
    }

    public static function getNavigationBadge(): ?string
    {
        $user = Filament::auth()->user();
        if (! $user) {
            return null;
        }

        $query = static::getEloquentQuery()
            ->whereMonth('distribution_date', now()->month)
            ->whereYear('distribution_date', now()->year);

        $count = $query->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'info';
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDistributions::route('/'),
        ];
    }
}
