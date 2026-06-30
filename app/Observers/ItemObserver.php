<?php

namespace App\Observers;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\ReferenceCodeService;

class ItemObserver
{
    public function creating(Item $item): void
    {
        if (! config('inventory.auto_generate_item_codes', true)) {
            return;
        }

        if (filled($item->item_code)) {
            return;
        }

        $category = $item->relationLoaded('category')
            ? $item->category
            : ($item->item_category_id ? ItemCategory::find($item->item_category_id) : null);

        if (! $category) {
            return;
        }

        $item->item_code = app(ReferenceCodeService::class)->forItemCode($category);
    }
}
