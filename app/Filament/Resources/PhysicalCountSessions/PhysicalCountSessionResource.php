<?php

namespace App\Filament\Resources\PhysicalCountSessions;

use App\Filament\Concerns\HasOwwaViewModalUrl;
use App\Filament\Resources\PhysicalCountSessions\Pages\CreatePhysicalCountSession;
use App\Filament\Resources\PhysicalCountSessions\Pages\EditPhysicalCountSession;
use App\Filament\Resources\PhysicalCountSessions\Pages\ListPhysicalCountSessions;
use App\Filament\Resources\PhysicalCountSessions\Pages\ScanPhysicalCountSession;
use App\Filament\Resources\PhysicalCountSessions\Pages\StartPhysicalCountMobile;
use App\Filament\Resources\PhysicalCountSessions\Pages\ViewPhysicalCountSession;
use App\Filament\Resources\PhysicalCountSessions\Schemas\PhysicalCountSessionForm;
use App\Filament\Resources\PhysicalCountSessions\Schemas\PhysicalCountSessionInfolist;
use App\Filament\Resources\PhysicalCountSessions\Tables\PhysicalCountSessionsTable;
use App\Models\PhysicalCountSession;
use App\Support\CustodianOfficeScope;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PhysicalCountSessionResource extends Resource
{
    use HasOwwaViewModalUrl;

    protected static ?string $model = PhysicalCountSession::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Physical counts';

    protected static ?string $modelLabel = 'Physical count';

    protected static ?string $pluralModelLabel = 'Physical counts';

    public static function canViewAny(): bool
    {
        $user = \Filament\Facades\Filament::auth()->user();

        return $user instanceof \App\Models\User && $user->isSupplyCustodian();
    }

    public static function getEloquentQuery(): Builder
    {
        return CustodianOfficeScope::applyOfficeColumn(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return PhysicalCountSessionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PhysicalCountSessionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PhysicalCountSessionsTable::configure($table);
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
            'index' => ListPhysicalCountSessions::route('/'),
            'start-mobile' => StartPhysicalCountMobile::route('/start-mobile'),
            'create' => CreatePhysicalCountSession::route('/create'),
            'view' => ViewPhysicalCountSession::route('/{record}'),
            'edit' => EditPhysicalCountSession::route('/{record}/edit'),
            'scan' => ScanPhysicalCountSession::route('/{record}/scan'),
        ];
    }
}
