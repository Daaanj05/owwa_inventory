<?php

namespace App\Filament\Resources\Offices;

use App\Filament\Concerns\HasOwwaViewModalUrl;
use App\Filament\Resources\Offices\Pages\ListOffices;
use App\Filament\Resources\Offices\Schemas\OfficeForm;
use App\Filament\Resources\Offices\Tables\OfficesTable;
use App\Models\Office;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class OfficeResource extends Resource
{
    use HasOwwaViewModalUrl;

    protected static ?string $model = Office::class;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->active();
    }

    public static function form(Schema $schema): Schema
    {
        return OfficeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OfficesTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->isSystemAdmin();
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
            'index' => ListOffices::route('/'),
        ];
    }
}
