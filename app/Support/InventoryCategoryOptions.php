<?php

namespace App\Support;

use App\Models\ItemCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class InventoryCategoryOptions
{
    public const CACHE_KEY = 'inventory.categories.active.sorted';

    public const LEGACY_OPTIONS_CACHE_KEY = 'item_categories.options';

    public const CACHE_TTL_SECONDS = 3600;

    /**
     * @return array<int, string>
     */
    public static function allActiveCategoryOptions(): array
    {
        return self::sortedActiveCategories()
            ->mapWithKeys(fn (ItemCategory $category): array => [$category->id => $category->name])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function propertyCategoryOptions(): array
    {
        return self::sortedActiveCategories()
            ->filter(fn (ItemCategory $category): bool => self::isPropertyCategorySlug($category->getTemplateSlug()))
            ->mapWithKeys(fn (ItemCategory $category): array => [$category->id => $category->name])
            ->all();
    }

    public static function defaultConsumablesCategoryId(): int
    {
        $category = self::sortedActiveCategories()
            ->first(fn (ItemCategory $category): bool => $category->getTemplateSlug() === 'consumables');

        if ($category !== null) {
            return (int) $category->id;
        }

        return (int) ItemCategory::query()->whereNull('archived_at')->orderBy('name')->value('id');
    }

    public static function isPropertyCategorySlug(string $slug): bool
    {
        return in_array($slug, ['ppe', 'semi_expendable'], true);
    }

    public static function isProcurementAnalyticsSlug(string $slug): bool
    {
        return in_array($slug, ['consumables', 'semi_expendable'], true);
    }

    /**
     * Categories in scope for Procurement Analytics (excludes PPE).
     *
     * @return Collection<int, ItemCategory>
     */
    public static function procurementAnalyticsCategories(): Collection
    {
        return self::sortedActiveCategories()
            ->filter(fn (ItemCategory $category): bool => self::isProcurementAnalyticsSlug($category->getTemplateSlug()))
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    public static function procurementAnalyticsCategoryIds(): Collection
    {
        return self::procurementAnalyticsCategories()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    public static function categoryIdsForSlug(string $slug): Collection
    {
        return self::sortedActiveCategories()
            ->filter(fn (ItemCategory $category): bool => $category->getTemplateSlug() === $slug)
            ->pluck('id')
            ->values();
    }

    /**
     * @return Collection<int, ItemCategory>
     */
    public static function sortedActiveCategories(): Collection
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            return self::querySortedActiveCategories()
                ->map(fn (ItemCategory $category): array => $category->getAttributes())
                ->all();
        });

        return collect($rows)
            ->map(fn (array $attributes): ItemCategory => (new ItemCategory)->newFromBuilder($attributes))
            ->values();
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::LEGACY_OPTIONS_CACHE_KEY);
    }

    /**
     * @return Collection<int, ItemCategory>
     */
    protected static function querySortedActiveCategories(): Collection
    {
        return ItemCategory::query()
            ->whereNull('archived_at')
            ->get()
            ->sort(function (ItemCategory $left, ItemCategory $right): int {
                $leftWeight = self::navigationWeight($left->getTemplateSlug());
                $rightWeight = self::navigationWeight($right->getTemplateSlug());

                if ($leftWeight !== $rightWeight) {
                    return $leftWeight <=> $rightWeight;
                }

                return strcasecmp($left->name, $right->name);
            })
            ->values();
    }

    public static function navigationWeight(string $slug): int
    {
        return match ($slug) {
            'consumables' => 1,
            'semi_expendable' => 2,
            'ppe' => 3,
            default => 10,
        };
    }

    public static function sortRankForCategory(?ItemCategory $category): int
    {
        if ($category === null) {
            return 99;
        }

        return self::navigationWeight($category->getTemplateSlug());
    }
}
