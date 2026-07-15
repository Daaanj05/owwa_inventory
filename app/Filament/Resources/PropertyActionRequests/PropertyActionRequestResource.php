<?php

namespace App\Filament\Resources\PropertyActionRequests;

use App\Filament\Resources\PropertyActionRequests\Pages\ListPropertyActionRequests;
use App\Filament\Resources\PropertyActionRequests\Schemas\PropertyActionRequestForm;
use App\Filament\Resources\PropertyActionRequests\Tables\PropertyActionRequestsTable;
use App\Models\PropertyActionRequest;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PropertyActionRequestResource extends Resource
{
    protected static ?string $model = PropertyActionRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Requisitions';

    protected static ?string $navigationLabel = 'Property Return';

    protected static ?string $modelLabel = 'Property Return';

    protected static ?string $pluralModelLabel = 'Property Returns';

    protected static ?int $navigationSort = 25;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['lines.issuance.item.category', 'requestedBy', 'accountableUser']);
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isEmployee()) {
            return $query->where('requested_by', $user->id);
        }

        if ($user->isUnitConsolidator()) {
            return $query->where(function (Builder $scope) use ($user): void {
                $scope->where('accountable_user_id', $user->id)
                    ->orWhere('office_id', $user->office_id);
            });
        }

        if ($user->isSupplyCustodian()) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return PropertyActionRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PropertyActionRequestsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && (
            $user->isEmployee()
            || $user->isUnitConsolidator()
            || $user->isSupplyCustodian()
        );
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && (
            $user->isEmployee()
            || $user->isUnitConsolidator()
        );
    }

    public static function canEdit(Model $record): bool
    {
        if (! $record instanceof PropertyActionRequest) {
            return false;
        }

        $user = Filament::auth()->user();

        return $user instanceof User
            && $user->isUnitConsolidator()
            && $record->status === PropertyActionRequest::STATUS_PENDING_SC;
    }

    public static function createUrlForIssuance(int $issuanceId, string $actionType): string
    {
        return static::getUrl('index', [
            'create' => 1,
            'issuance_id' => $issuanceId,
            'action_type' => $actionType,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPropertyActionRequests::route('/'),
        ];
    }
}
