<?php

namespace App\Filament\Resources\Transfers;

use App\Filament\Concerns\SyncsActiveItemCategory;
use App\Models\ItemCategory;
use App\Models\Transfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TransferCustodyQuery
{
    public static function apply(Builder $query): Builder
    {
        $transferCategoryIds = ItemCategory::query()
            ->get()
            ->filter(fn (ItemCategory $category): bool => in_array(
                $category->getTemplateSlug(),
                ['ppe', 'semi_expendable'],
                true,
            ))
            ->pluck('id');

        $query->whereHas('item', function (Builder $itemQuery) use ($transferCategoryIds): void {
            $itemQuery->whereIn('item_category_id', $transferCategoryIds);
        });

        $categoryId = SyncsActiveItemCategory::resolveCategoryIdFromContext();
        if ($categoryId > 0) {
            $query->whereHas('item', function (Builder $itemQuery) use ($categoryId): void {
                $itemQuery->where('item_category_id', $categoryId);
            });
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public static function forBulkExport(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        abort_unless(TransferResource::canViewAny(), 403);

        return Transfer::query()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereKey($ids)
            ->tap(fn (Builder $query) => self::apply($query))
            ->get();
    }
}
