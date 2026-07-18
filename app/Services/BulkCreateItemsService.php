<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Support\ConsumableInventoryType;
use App\Support\ItemPropertyClass;
use App\Support\PpePropertyType;
use App\Support\SemiExpendableUsefulLife;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BulkCreateItemsService
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, Item>
     */
    public function createMany(int $categoryId, array $rows): array
    {
        $category = ItemCategory::query()->findOrFail($categoryId);
        $slug = $category->getTemplateSlug();
        $normalizedRows = $this->normalizeAndValidateRows($category, $slug, $rows);

        return DB::transaction(function () use ($category, $normalizedRows): array {
            $created = [];

            foreach ($normalizedRows as $row) {
                $item = new Item([
                    'item_category_id' => $category->id,
                    'base_name' => $row['base_name'],
                    'sub_item' => $row['sub_item'],
                    'name' => $row['name'],
                    'unit' => $row['unit'],
                    'reorder_level' => $row['reorder_level'],
                    'days_to_consume' => $row['days_to_consume'],
                    'inventory_type' => $row['inventory_type'],
                    'property_class' => $row['property_class'],
                    'ppe_type' => $row['ppe_type'],
                    'uacs_object_code_id' => $row['uacs_object_code_id'],
                    'estimated_useful_life' => $row['estimated_useful_life'],
                    'description' => $row['description'],
                ]);
                $item->setRelation('category', $category);
                $item->save();
                $created[] = $item;
            }

            return $created;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{
     *     base_name: string,
     *     sub_item: ?string,
     *     name: string,
     *     unit: string,
     *     reorder_level: int,
     *     days_to_consume: ?int,
     *     inventory_type: ?string,
     *     property_class: ?string,
     *     ppe_type: ?string,
     *     uacs_object_code_id: ?int,
     *     estimated_useful_life: ?string,
     *     description: ?string
     * }>
     */
    protected function normalizeAndValidateRows(ItemCategory $category, string $slug, array $rows): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages([
                'items' => 'Add at least one item row.',
            ]);
        }

        $normalized = [];
        $seenNames = [];
        $errors = [];

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $baseName = trim((string) ($row['base_name'] ?? ''));
            $subItem = filled($row['sub_item'] ?? null) ? trim((string) $row['sub_item']) : null;
            $unit = trim((string) ($row['unit'] ?? ''));
            $description = filled($row['description'] ?? null) ? trim((string) $row['description']) : null;
            $isBlankRow = $baseName === ''
                && $subItem === null
                && $description === null
                && blank($row['days_to_consume'] ?? null)
                && blank($row['inventory_type'] ?? null)
                && blank($row['property_class'] ?? null)
                && blank($row['ppe_type'] ?? null)
                && blank($row['uacs_object_code_id'] ?? null)
                && blank($row['estimated_useful_life'] ?? null)
                && (blank($row['unit'] ?? null) || $unit === 'piece')
                && (! isset($row['reorder_level']) || (int) $row['reorder_level'] === 0);

            if ($isBlankRow) {
                continue;
            }

            $name = Item::mergeDisplayName($baseName, $subItem);
            $nameKey = mb_strtolower($name);

            if ($baseName === '') {
                $errors["items.{$index}.base_name"] = 'Base item is required.';
            }

            if ($unit === '') {
                $errors["items.{$index}.unit"] = 'Measurement unit is required.';
            }

            if ($name !== '' && isset($seenNames[$nameKey])) {
                $errors["items.{$index}.base_name"] = "Duplicate item in this batch: {$name}.";
            }

            if ($name !== '') {
                $seenNames[$nameKey] = true;
            }

            $reorderLevel = $row['reorder_level'] ?? 0;
            if (! is_numeric($reorderLevel) || (int) $reorderLevel < 0) {
                $errors["items.{$index}.reorder_level"] = 'Reorder point must be 0 or greater.';
            }

            $daysToConsume = null;
            $inventoryType = null;
            $propertyClass = null;
            $ppeType = null;
            $uacsId = null;
            $estimatedUsefulLife = null;

            if ($slug === 'consumables') {
                if (filled($row['days_to_consume'] ?? null)) {
                    if (! is_numeric($row['days_to_consume']) || (int) $row['days_to_consume'] < 0) {
                        $errors["items.{$index}.days_to_consume"] = 'Days to consume must be 0 or greater.';
                    } else {
                        $daysToConsume = (int) $row['days_to_consume'];
                    }
                }

                $inventoryType = filled($row['inventory_type'] ?? null) ? (string) $row['inventory_type'] : null;
                if ($inventoryType === null || ConsumableInventoryType::normalize($inventoryType) === null) {
                    $errors["items.{$index}.inventory_type"] = 'Inventory type is required.';
                } else {
                    $inventoryType = ConsumableInventoryType::normalize($inventoryType);
                }
            }

            if ($slug === 'semi_expendable') {
                $propertyClass = filled($row['property_class'] ?? null) ? (string) $row['property_class'] : null;
                $uacsId = filled($row['uacs_object_code_id'] ?? null) ? (int) $row['uacs_object_code_id'] : null;

                if ($propertyClass === null || ! array_key_exists($propertyClass, ItemPropertyClass::options())) {
                    $errors["items.{$index}.property_class"] = 'Property class is required.';
                }

                if ($uacsId === null || $uacsId <= 0) {
                    $errors["items.{$index}.uacs_object_code_id"] = 'UACS object code is required.';
                }

                $estimatedUsefulLife = filled($row['estimated_useful_life'] ?? null)
                    ? trim((string) $row['estimated_useful_life'])
                    : null;

                if ($estimatedUsefulLife === null || $estimatedUsefulLife === '') {
                    $errors["items.{$index}.estimated_useful_life"] = 'Estimated useful life is required.';
                } else {
                    try {
                        SemiExpendableUsefulLife::assertEligibleForSemi($estimatedUsefulLife);
                    } catch (ValidationException $exception) {
                        $errors["items.{$index}.estimated_useful_life"] = $exception->validator->errors()->first('estimated_useful_life')
                            ?: 'Invalid estimated useful life.';
                    }
                }
            }

            if ($slug === 'ppe') {
                $ppeType = filled($row['ppe_type'] ?? null) ? (string) $row['ppe_type'] : null;
                $uacsId = filled($row['uacs_object_code_id'] ?? null) ? (int) $row['uacs_object_code_id'] : null;

                if ($ppeType === null || PpePropertyType::normalize($ppeType) === null) {
                    $errors["items.{$index}.ppe_type"] = 'Type of PPE is required.';
                } else {
                    $ppeType = PpePropertyType::normalize($ppeType);
                }

                if ($uacsId === null || $uacsId <= 0) {
                    $errors["items.{$index}.uacs_object_code_id"] = 'UACS object code is required.';
                }
            }

            $normalized[] = [
                'base_name' => $baseName,
                'sub_item' => $subItem,
                'name' => $name,
                'unit' => $unit,
                'reorder_level' => (int) $reorderLevel,
                'days_to_consume' => $daysToConsume,
                'inventory_type' => $inventoryType,
                'property_class' => $propertyClass,
                'ppe_type' => $ppeType,
                'uacs_object_code_id' => $uacsId,
                'estimated_useful_life' => $estimatedUsefulLife,
                'description' => $description,
            ];
        }

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'items' => 'Add at least one item row.',
            ]);
        }

        $existingNames = Item::query()
            ->active()
            ->where('item_category_id', $category->id)
            ->whereIn('name', collect($normalized)->pluck('name')->filter()->all())
            ->pluck('name')
            ->mapWithKeys(fn (string $name): array => [mb_strtolower($name) => $name])
            ->all();

        foreach ($normalized as $index => $row) {
            $key = mb_strtolower($row['name']);
            if ($row['name'] !== '' && isset($existingNames[$key])) {
                $errors["items.{$index}.base_name"] = "Item already exists in this category: {$existingNames[$key]}.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }
}
