<?php

namespace App\Filament\Concerns;

use App\Filament\Pages\InventoryCategoryDashboard;
use App\Models\ItemCategory;

trait SyncsActiveItemCategory
{
    /**
     * Resolve the active inventory category from the request query or session,
     * persist it to the session, and optionally send first-time visitors to the dashboard.
     */
    protected function syncActiveItemCategoryFromRequest(bool $redirectWhenMissing = true): int
    {
        $hadSession = session()->has('active_item_category_id');
        $fromQuery = filled(request()->query('category'));

        $categoryId = $fromQuery
            ? (int) request()->query('category')
            : (int) session('active_item_category_id', 0);

        if ($categoryId <= 0) {
            $categoryId = (int) ItemCategory::query()->orderBy('name')->value('id');
        }

        if ($categoryId <= 0) {
            abort(404);
        }

        session()->put('active_item_category_id', $categoryId);

        if ($redirectWhenMissing && ! $fromQuery && ! $hadSession) {
            $this->redirect(InventoryCategoryDashboard::getUrl(['category' => $categoryId]));
        }

        return $categoryId;
    }

    protected static function urlWithActiveItemCategory(string $url, int|string|null $categoryId): string
    {
        if (! filled($categoryId)) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query(['category' => (int) $categoryId]);
    }
}
