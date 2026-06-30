<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class InventoryPlanCategoryQuery
{
    public static function apply(Builder $query): Builder
    {
        $categoryId = session('active_item_category_id');

        if (! filled($categoryId)) {
            return $query->whereRaw('1 = 0');
        }

        $categoryId = (int) $categoryId;

        return $query->where(function (Builder $scoped) use ($categoryId): void {
            $scoped
                ->where('item_category_id', $categoryId)
                ->orWhereHas('lines', fn (Builder $lineQuery): Builder => $lineQuery->where('item_category_id', $categoryId));
        });
    }
}
