<?php

namespace App\Filament\Concerns;

use App\Filament\Pages\InventoryCategoryDashboard;
use App\Models\ItemCategory;

trait SyncsActiveItemCategory
{
    /**
     * Resolve the active inventory category from URL/Livewire state, persist to session,
     * and optionally send first-time visitors to the dashboard.
     */
    protected function syncActiveItemCategoryFromRequest(bool $redirectWhenMissing = true): int
    {
        $hadSession = session()->has('active_item_category_id');
        $fromQuery = filled(request()->query('category'))
            || (property_exists($this, 'category') && filled($this->category));

        $categoryId = self::resolveCategoryIdFromContext(
            property_exists($this, 'category') && filled($this->category)
                ? (int) $this->category
                : null,
        );

        if (property_exists($this, 'category')) {
            $this->category = $categoryId;
        }

        session()->put('active_item_category_id', $categoryId);

        if ($redirectWhenMissing && ! $fromQuery && ! $hadSession) {
            $this->redirect(InventoryCategoryDashboard::getUrl(['category' => $categoryId]));
        }

        return $categoryId;
    }

    protected function activeItemCategoryId(): int
    {
        $livewireCategory = property_exists($this, 'category') && filled($this->category)
            ? (int) $this->category
            : null;

        return self::resolveCategoryIdFromContext($livewireCategory);
    }

    public static function resolveCategoryIdFromContext(?int $livewireCategory = null): int
    {
        if (filled($livewireCategory) && (int) $livewireCategory > 0) {
            return self::resolveActiveItemCategoryId((int) $livewireCategory);
        }

        if (filled(request()->query('category'))) {
            return self::resolveActiveItemCategoryId((int) request()->query('category'));
        }

        return self::resolveActiveItemCategoryId((int) session('active_item_category_id', 0));
    }

    protected static function resolveActiveItemCategoryId(int $categoryId): int
    {
        if ($categoryId > 0 && ! ItemCategory::query()->whereKey($categoryId)->whereNull('archived_at')->exists()) {
            $categoryId = 0;
        }

        if ($categoryId <= 0) {
            $categoryId = (int) ItemCategory::query()->whereNull('archived_at')->orderBy('name')->value('id');
        }

        if ($categoryId <= 0) {
            abort(404);
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
