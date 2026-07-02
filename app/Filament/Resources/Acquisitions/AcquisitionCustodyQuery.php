<?php

namespace App\Filament\Resources\Acquisitions;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Models\Acquisition;
use App\Support\CustodianOfficeScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AcquisitionCustodyQuery
{
    public static function apply(Builder $query): Builder
    {
        $categoryId = SyncsActiveItemCategory::resolveCategoryIdFromContext();
        if ($categoryId > 0) {
            $query->whereHas('item', function (Builder $itemQuery) use ($categoryId): void {
                $itemQuery->where('item_category_id', $categoryId);
            });
        } else {
            $query->whereRaw('1 = 0');
        }

        return CustodianOfficeScope::applyOfficeColumn($query);
    }

    public static function forBulkExport(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        abort_unless(AcquisitionResource::canViewAny(), 403);

        return Acquisition::query()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereKey($ids)
            ->tap(fn (Builder $query) => self::apply($query))
            ->get();
    }
}
