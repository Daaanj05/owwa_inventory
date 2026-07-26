<?php

namespace App\Filament\Resources\UacsObjectCodes;

use App\Filament\Concerns\HasOwwaViewModalUrl;
use App\Filament\Resources\UacsObjectCodes\Pages\ListUacsObjectCodes;
use App\Filament\Resources\UacsObjectCodes\Schemas\UacsObjectCodeForm;
use App\Filament\Resources\UacsObjectCodes\Tables\UacsObjectCodesTable;
use App\Models\UacsObjectCode;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class UacsObjectCodeResource extends Resource
{
    use HasOwwaViewModalUrl;

    protected static ?string $model = UacsObjectCode::class;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static ?int $navigationSort = 25;

    protected static ?string $modelLabel = 'UACS object code';

    protected static ?string $pluralModelLabel = 'UACS object codes';

    protected static ?string $navigationLabel = 'UACS object codes';

    public static function form(Schema $schema): Schema
    {
        return UacsObjectCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UacsObjectCodesTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->isSystemAdmin();
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUacsObjectCodes::route('/'),
        ];
    }
}
