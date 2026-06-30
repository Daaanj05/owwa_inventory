<?php

namespace App\Filament\Resources\Acquisitions;

use App\Models\Acquisition;
use App\Support\CustodianOfficeScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AcquisitionCustodyQuery
{
    public static function apply(Builder $query): Builder
    {
        $categoryId = session('active_item_category_id');
        if (filled($categoryId)) {
            $query->whereHas('item', function (Builder $itemQuery) use ($categoryId): void {
                $itemQuery->where('item_category_id', (int) $categoryId);
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
