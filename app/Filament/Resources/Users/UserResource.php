<?php

namespace App\Filament\Resources\Users;

use App\Filament\Concerns\HasOwwaViewModalUrl;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
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

class UserResource extends Resource
{
    use HasOwwaViewModalUrl;

    protected static ?string $model = User::class;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 5;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['pendingPasswordResetRequest']);
        $user = Filament::auth()->user();
        if ($user && $user->isUnitConsolidator() && $user->office_id) {
            $query->where('office_id', $user->office_id)
                ->where('role', User::ROLE_EMPLOYEE);
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->isSystemAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        $user = Filament::auth()->user();
        if (! $user) {
            return false;
        }
        // System Admin can manage other users, but cannot edit their own account here.
        if (! $user->isSystemAdmin()) {
            return false;
        }

        return (int) $record->id !== (int) $user->id;
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
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
            'index' => ListUsers::route('/'),
        ];
    }
}
