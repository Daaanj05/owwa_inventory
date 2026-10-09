<?php

namespace App\Filament\Concerns;

use App\Models\ItemCategory;

trait SyncsActiveItemCategory
{
    /**
     * Resolve the active inventory category from URL/Livewire state and persist it to the session.
     */
    protected function syncActiveItemCategoryFromRequest(): int
    {
        $categoryId = self::resolveCategoryIdFromContext(
            property_exists($this, 'category') && filled($this->category)
                ? (int) $this->category
                : null,
        );

        if (property_exists($this, 'category')) {
            $this->category = $categoryId;
        }

        session()->put('active_item_category_id', $categoryId);

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

        // Prefer the current Livewire page's `category` (URL-bound) over the shared
        // session so two browser tabs on different inventory categories stay isolated.
        $fromComponent = self::categoryIdFromCurrentLivewireComponent();
        if ($fromComponent > 0) {
            return self::resolveActiveItemCategoryId($fromComponent);
        }

        if (filled(request()->query('category'))) {
            return self::resolveActiveItemCategoryId((int) request()->query('category'));
        }

        return self::resolveActiveItemCategoryId((int) session('active_item_category_id', 0));
    }

    protected static function categoryIdFromCurrentLivewireComponent(): int
    {
        try {
            $component = \Livewire\Livewire::current();
        } catch (\Throwable) {
            return 0;
        }

        if (! is_object($component) || ! property_exists($component, 'category')) {
            return 0;
        }

        $category = $component->category ?? null;

        return filled($category) ? (int) $category : 0;
    }

    protected static function resolveActiveItemCategoryId(int $categoryId): int
    {
        $memoKey = 'owwa.resolved_active_item_category_id.'.$categoryId;
        $request = request();

        if ($request->attributes->has($memoKey)) {
            return (int) $request->attributes->get($memoKey);
        }

        $resolved = $categoryId;

        if ($resolved > 0 && ! ItemCategory::query()->whereKey($resolved)->whereNull('archived_at')->exists()) {
            $resolved = 0;
        }

        if ($resolved <= 0) {
            $resolved = (int) ItemCategory::query()->whereNull('archived_at')->orderBy('name')->value('id');
        }

        if ($resolved <= 0) {
            abort(404);
        }

        $request->attributes->set($memoKey, $resolved);

        return $resolved;
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
