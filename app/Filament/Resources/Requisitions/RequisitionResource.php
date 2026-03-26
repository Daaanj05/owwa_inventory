<?php

namespace App\Filament\Resources\Requisitions;

use App\Filament\Resources\Requisitions\Pages\CreateRequisition;
use App\Filament\Resources\Requisitions\Pages\EditRequisition;
use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Filament\Resources\Requisitions\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Requisitions\Schemas\RequisitionForm;
use App\Filament\Resources\Requisitions\Tables\RequisitionsTable;
use App\Models\Requisition;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RequisitionResource extends Resource
{
    protected static ?string $model = Requisition::class;

    protected static string|UnitEnum|null $navigationGroup = 'Requisitions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Requisition';

    protected static ?string $pluralModelLabel = 'Requisitions';

    public static function getNavigationBadge(): ?string
    {
        $user = Filament::auth()->user();
        $query = static::getModel()::where('status', Requisition::STATUS_PENDING);
        app(\App\Services\FiscalYearService::class)->applyDateRangeFilter($query, 'created_at');
        if ($user && $user->isAuthorizedPersonnel() && $user->office_id) {
            $query->where('office_id', $user->office_id);
            if ($user->department_id) {
                $query->where('department_id', $user->department_id);
            }
        } elseif ($user && $user->isSupplyCustodian()) {
            $query->whereHas('requestedBy', function (Builder $q): void {
                $q->where('role', User::ROLE_AUTHORIZED_PERSONNEL)
                    ->orWhere('role', User::ROLE_SUPPLY_CUSTODIAN);
            });
        } elseif ($user && $user->isEmployee()) {
            $query->where('requested_by', $user->id);
        }
        $pending = $query->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return RequisitionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RequisitionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRequisitions::route('/'),
            'create' => CreateRequisition::route('/create'),
            'edit' => EditRequisition::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $fiscal = app(\App\Services\FiscalYearService::class);
        $fiscal->applyDateRangeFilter($query, 'created_at');

        /** @var \App\Models\User|null $user */
        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSupplyCustodian()) {
            // Custodian sees only requisitions submitted by Unit Heads (consolidated requests) or by the custodian.
            // Employee requests are compiled by the Unit Head into one requisition before reaching the custodian.
            return $query->whereHas('requestedBy', function (Builder $q): void {
                $q->where('role', User::ROLE_AUTHORIZED_PERSONNEL)
                    ->orWhere('role', User::ROLE_SUPPLY_CUSTODIAN);
            });
        }

        if ($user->isAuthorizedPersonnel()) {
            // Unit Head sees only requisitions from their own office/department (including employee requests).
            // They compile employee requests into one consolidated requisition (Create) to send to the Supply Custodian.
            if ($user->office_id) {
                $query->where('office_id', $user->office_id);
            }

            if ($user->department_id) {
                $query->where('department_id', $user->department_id);
            }

            return $query;
        }

        if ($user->isEmployee()) {
            // Employees see only their own requests.
            return $query->where('requested_by', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSupplyCustodian()
            || $user?->isAuthorizedPersonnel()
            || $user?->isEmployee();
    }
}
