<?php

namespace App\Filament\Resources\ReferenceSeries;

use App\Filament\Concerns\HasOwwaViewModalUrl;
use App\Filament\Resources\ReferenceSeries\Pages\ListReferenceSeries;
use App\Filament\Resources\ReferenceSeries\Schemas\ReferenceSeriesForm;
use App\Filament\Resources\ReferenceSeries\Tables\ReferenceSeriesTable;
use App\Models\ReferenceSeries;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ReferenceSeriesResource extends Resource
{
    use HasOwwaViewModalUrl;

    protected static ?string $model = ReferenceSeries::class;

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Reference number format';

    protected static ?string $pluralModelLabel = 'Reference number formats';

    protected static ?string $navigationLabel = 'Reference numbers';

    public static function form(Schema $schema): Schema
    {
        return ReferenceSeriesForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReferenceSeriesTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->isSystemAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferenceSeries::route('/'),
        ];
    }
}
