<?php

namespace App\Support;

use App\Models\ItemCategory;
use Illuminate\Support\Collection;

class InventoryCategoryOptions
{
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

    /**
     * @return Collection<int, int>
     */
    public static function categoryIdsForSlug(string $slug): Collection
    {
        return ItemCategory::query()
            ->whereNull('archived_at')
            ->get()
            ->filter(fn (ItemCategory $category): bool => $category->getTemplateSlug() === $slug)
            ->pluck('id')
            ->values();
    }

    /**
     * @return Collection<int, ItemCategory>
     */
    public static function sortedActiveCategories(): Collection
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
}
