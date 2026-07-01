<?php

namespace App\Filament\Resources\Acquisitions\Paperwork;

use App\Filament\Concerns\HasOwwaViewModalUrl;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Acquisitions\Paperwork\Schemas\AcquisitionPaperworkForm;
use App\Filament\Resources\Acquisitions\Paperwork\Schemas\AcquisitionPaperworkInfolist;
use App\Filament\Resources\Acquisitions\Paperwork\Tables\AcquisitionPaperworkTable;
use App\Models\AcquisitionPaperwork;
use App\Support\CustodianOfficeScope;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AcquisitionPaperworkResource extends Resource
{
    use HasOwwaViewModalUrl;

    protected static ?string $model = AcquisitionPaperwork::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'Acquisition paperwork';

    protected static ?string $pluralModelLabel = 'Acquisition paperwork';

    public static function canViewAny(): bool
    {
        $user = \Filament\Facades\Filament::auth()->user();

        return $user instanceof \App\Models\User && $user->isSupplyCustodian();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = CustodianOfficeScope::applyOfficeColumn(parent::getEloquentQuery());

        $categoryId = (int) session('active_item_category_id', 0);
        if ($categoryId > 0) {
            $query->where('item_category_id', $categoryId);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return AcquisitionPaperworkForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AcquisitionPaperworkInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AcquisitionPaperworkTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $extraParams
     */
    public static function viewModalUrl(\Illuminate\Database\Eloquent\Model|int $record, array $extraParams = []): string
    {
        return AcquisitionResource::viewModalUrl($record, $extraParams);
    }
}
