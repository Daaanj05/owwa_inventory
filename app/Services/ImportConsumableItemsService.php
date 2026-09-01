<?php

namespace App\Services;

use App\Filament\Resources\Items\Support\ItemOpeningStockFields;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\UacsObjectCode;
use App\Models\User;
use App\Support\ConsumableInventoryType;
use App\Support\ConsumableItemSpreadsheetReader;
use App\Support\ItemMeasurementUnitInput;
use App\Support\ItemPropertyClass;
use App\Support\PpePropertyType;
use App\Support\PpeValueCategory;
use App\Support\SemiExpendableUsefulLife;
use App\Support\SemiExpendableValueCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportConsumableItemsService
{
    public function __construct(
        protected ConsumableItemSpreadsheetReader $reader,
        protected OpeningBalanceService $openingBalanceService,
    ) {}

    /**
     * @return array{
     *     created: int,
     *     createdNames: list<string>,
     *     updatedNames: list<string>,
     *     stockFilled: list<string>,
     *     skippedHasStock: list<string>,
     *     skippedExistingNoQty: list<string>,
     *     skippedInFile: list<string>,
     *     invalid: list<array{row: int, name: string, reason: string}>,
     *     rows: list<array{
     *         status: string,
     *         excel_row: int,
     *         excel: array{base: string, sub: ?string, unit: string, qty: ?int, item_name: ?string},
     *         actual: ?array{base: string, sub: ?string, unit: string, name: string},
     *         reason: ?string
     *     }>
     * }
     */
    public function importFromPath(
        string $absolutePath,
        int $categoryId,
        ?int $officeId = null,
        ?User $recordedBy = null,
    ): array {
        $category = ItemCategory::query()->findOrFail($categoryId);
        $slug = $category->getTemplateSlug();

        if (! in_array($slug, ['consumables', 'semi_expendable', 'ppe'], true)) {
            throw ValidationException::withMessages([
                'file' => 'Excel import is not available for this category.',
            ]);
        }

        $metadata = $this->reader->readWithMetadata($absolutePath);
        $this->assertHeadersMatchCategory($metadata['headerMap'], $slug);
        $rows = $metadata['rows'];
        $officeId ??= ItemOpeningStockFields::resolveRegionalOfficeId();

        return $this->importRows($category, $rows, $officeId, $recordedBy);
    }

    /**
     * @param  array<string, int>|null  $headerMap
     */
    protected function assertHeadersMatchCategory(?array $headerMap, string $activeSlug): void
    {
        $detected = ConsumableItemSpreadsheetReader::distinctiveCategoriesFromHeaderMap($headerMap);

        if ($detected === []) {
            return;
        }

        if (count($detected) > 1) {
            throw ValidationException::withMessages([
                'file' => 'This file mixes columns from more than one import template. Download the sample file for the category you are importing into and try again.',
            ]);
        }

        $detectedSlug = $detected[0];
        if ($detectedSlug === $activeSlug) {
            return;
        }

        $label = ConsumableItemSpreadsheetReader::categoryImportLabel($detectedSlug);

        throw ValidationException::withMessages([
            'file' => "This file looks like a {$label} import. Open {$label} Items and try again, or download that sample file.",
        ]);
    }

    /**
     * @param  list<array{
     *     row: int,
     *     base_name: string,
     *     sub_item: ?string,
     *     unit: string,
     *     opening_quantity: ?int,
     *     name: string,
     *     item_name?: ?string,
     *     excel_base_name?: string,
     *     excel_sub_item?: ?string,
     *     excel_unit?: string
     * }>  $rows
     * @return array{
     *     created: int,
     *     createdNames: list<string>,
     *     updatedNames: list<string>,
     *     stockFilled: list<string>,
     *     skippedHasStock: list<string>,
     *     skippedExistingNoQty: list<string>,
     *     skippedInFile: list<string>,
     *     invalid: list<array{row: int, name: string, reason: string}>,
     *     rows: list<array{
     *         status: string,
     *         excel_row: int,
     *         excel: array{base: string, sub: ?string, unit: string, qty: ?int, item_name: ?string},
     *         actual: ?array{base: string, sub: ?string, unit: string, name: string},
     *         reason: ?string
     *     }>
     * }
     */
    public function importRows(
        ItemCategory $category,
        array $rows,
        ?int $officeId = null,
        ?User $recordedBy = null,
    ): array {
        $result = [
            'created' => 0,
            'createdNames' => [],
            'updatedNames' => [],
            'stockFilled' => [],
            'skippedHasStock' => [],
            'skippedExistingNoQty' => [],
            'skippedInFile' => [],
            'invalid' => [],
            'rows' => [],
        ];

        if ($rows === []) {
            return $result;
        }

        $slug = $category->getTemplateSlug();
        $officeId ??= ItemOpeningStockFields::resolveRegionalOfficeId();

        $existingByName = Item::query()
            ->active()
            ->where('item_category_id', $category->id)
            ->get([
                'id',
                'name',
                'base_name',
                'sub_item',
                'unit',
                'archived_at',
                'item_category_id',
                'inventory_type',
                'days_to_consume',
                'description',
                'reorder_level',
                'property_class',
                'ppe_type',
                'uacs_object_code_id',
                'estimated_useful_life',
            ])
            ->keyBy(fn (Item $item): string => ConsumableItemSpreadsheetReader::normalizeNameKey((string) $item->name));

        $seenInFile = [];

        DB::transaction(function () use (
            $category,
            $slug,
            $rows,
            $officeId,
            $recordedBy,
            &$result,
            &$existingByName,
            &$seenInFile,
        ): void {
            foreach ($rows as $row) {
                $baseName = trim((string) ($row['base_name'] ?? ''));
                $subItem = filled($row['sub_item'] ?? null) ? trim((string) $row['sub_item']) : null;
                $unit = trim((string) ($row['unit'] ?? ''));
                $quantity = $row['opening_quantity'] ?? null;
                $itemName = filled($row['item_name'] ?? null) ? trim((string) $row['item_name']) : null;
                $name = Item::mergeDisplayName($baseName, $subItem);
                $nameKey = ConsumableItemSpreadsheetReader::normalizeNameKey($name);
                $excelRow = (int) ($row['row'] ?? 0);
                $catalogResult = $this->resolveCatalogFields($row, $slug);
                if ($catalogResult['error'] !== null) {
                    $this->pushInvalid(
                        $result,
                        $excelRow,
                        $name !== '' ? $name : '(blank)',
                        $catalogResult['error'],
                        $this->excelSnapshot(
                            $row,
                            $baseName,
                            $subItem,
                            $unit,
                            $quantity,
                            $itemName,
                            $catalogResult['fields'],
                            $this->rawUnitCostFromRow($row),
                        ),
                    );

                    continue;
                }

                $catalogFields = $catalogResult['fields'];
                $unitCostResult = $this->resolveUnitCost($row);
                if ($unitCostResult['error'] !== null) {
                    $this->pushInvalid(
                        $result,
                        $excelRow,
                        $name !== '' ? $name : '(blank)',
                        $unitCostResult['error'],
                        $this->excelSnapshot(
                            $row,
                            $baseName,
                            $subItem,
                            $unit,
                            $quantity,
                            $itemName,
                            $catalogFields,
                            $unitCostResult['unit_cost'],
                        ),
                    );

                    continue;
                }

                $unitCost = $unitCostResult['unit_cost'];
                $excelSnapshot = $this->excelSnapshot(
                    $row,
                    $baseName,
                    $subItem,
                    $unit,
                    $quantity,
                    $itemName,
                    $catalogFields,
                    $unitCost,
                );

                if ($baseName === '') {
                    $this->pushInvalid($result, $excelRow, $name !== '' ? $name : '(blank)', 'Base item is required.', $excelSnapshot);

                    continue;
                }

                if ($unit === '' || ! ItemMeasurementUnitInput::isValid($unit)) {
                    $this->pushInvalid(
                        $result,
                        $excelRow,
                        $name,
                        'Measurement unit must be letters only (e.g. piece, ream, box).',
                        $excelSnapshot,
                    );

                    continue;
                }

                if ($quantity !== null && (! is_int($quantity) || $quantity < 0)) {
                    $this->pushInvalid(
                        $result,
                        $excelRow,
                        $name,
                        'Quantity must be a whole number of 0 or greater.',
                        $excelSnapshot,
                    );

                    continue;
                }

                if (isset($seenInFile[$nameKey])) {
                    $result['skippedInFile'][] = $name;
                    $result['rows'][] = [
                        'status' => 'skipped_duplicate',
                        'excel_row' => $excelRow,
                        'excel' => $excelSnapshot,
                        'actual' => $this->actualSnapshot(
                            base: $baseName,
                            sub: $subItem,
                            unit: $unit,
                            name: $name,
                            catalog: $catalogFields,
                        ),
                        'reason' => 'Duplicate row in this file.',
                    ];

                    continue;
                }

                $seenInFile[$nameKey] = true;

                /** @var Item|null $existing */
                $existing = $existingByName->get($nameKey);
                if ($existing === null && filled($itemName)) {
                    $existing = $existingByName->get(ConsumableItemSpreadsheetReader::normalizeNameKey($itemName));
                }

                if ($existing !== null) {
                    $catalogFilled = $this->fillMissingCatalogFields($existing, $catalogFields, $slug) !== [];
                    if ($catalogFilled) {
                        $existing->refresh();
                    }

                    $actual = $this->actualSnapshotFromItem($existing);

                    if ($quantity === null || $quantity < 1) {
                        if ($catalogFilled) {
                            $this->pushCatalogUpdated($result, $excelRow, $existing->name, $excelSnapshot, $actual);

                            continue;
                        }

                        $result['skippedExistingNoQty'][] = $existing->name;
                        $result['rows'][] = [
                            'status' => 'skipped_existing',
                            'excel_row' => $excelRow,
                            'excel' => $excelSnapshot,
                            'actual' => $actual,
                            'reason' => 'Already in catalog; no starting quantity in file.',
                        ];

                        continue;
                    }

                    if (! ItemOpeningStockFields::canSetStartingStock($existing, $officeId)) {
                        if ($catalogFilled) {
                            $this->pushCatalogUpdated($result, $excelRow, $existing->name, $excelSnapshot, $actual);

                            continue;
                        }

                        $result['skippedHasStock'][] = $existing->name;
                        $result['rows'][] = [
                            'status' => 'skipped_has_stock',
                            'excel_row' => $excelRow,
                            'excel' => $excelSnapshot,
                            'actual' => $actual,
                            'reason' => 'Already has stock.',
                        ];

                        continue;
                    }

                    if ($officeId === null || $officeId < 1) {
                        $this->pushInvalid(
                            $result,
                            $excelRow,
                            $existing->name,
                            'Regional supply office is not configured. Starting stock cannot be recorded.',
                            $excelSnapshot,
                            $actual,
                        );

                        continue;
                    }

                    $stockError = $this->validateStartingStockCost($slug, $quantity, $unitCost);
                    if ($stockError !== null) {
                        $this->pushInvalid(
                            $result,
                            $excelRow,
                            $existing->name,
                            $stockError,
                            $excelSnapshot,
                            $actual,
                        );

                        continue;
                    }

                    $this->openingBalanceService->setOpeningStock(
                        item: $existing,
                        officeId: (int) $officeId,
                        quantity: (int) $quantity,
                        unitCost: $unitCost,
                        recordedBy: $recordedBy,
                    );

                    $result['stockFilled'][] = $existing->name;
                    $result['rows'][] = [
                        'status' => 'stock_filled',
                        'excel_row' => $excelRow,
                        'excel' => $excelSnapshot,
                        'actual' => $this->actualSnapshotFromItem($existing, $unitCost ?? 0.0),
                        'reason' => null,
                    ];

                    continue;
                }

                if (($quantity ?? 0) >= 1 && ($officeId === null || $officeId < 1)) {
                    $this->pushInvalid(
                        $result,
                        $excelRow,
                        $name,
                        'Regional supply office is not configured. Starting stock cannot be recorded.',
                        $excelSnapshot,
                    );

                    continue;
                }

                $createError = $this->validateRequiredForCreate($slug, $catalogFields);
                if ($createError !== null) {
                    $this->pushInvalid(
                        $result,
                        $excelRow,
                        $name,
                        $createError,
                        $excelSnapshot,
                    );

                    continue;
                }

                $stockError = $this->validateStartingStockCost($slug, $quantity, $unitCost);
                if ($stockError !== null) {
                    $this->pushInvalid(
                        $result,
                        $excelRow,
                        $name,
                        $stockError,
                        $excelSnapshot,
                    );

                    continue;
                }

                $item = new Item([
                    'item_category_id' => $category->id,
                    'base_name' => $baseName,
                    'sub_item' => $subItem,
                    'name' => $name,
                    'unit' => $unit,
                    'reorder_level' => $catalogFields['reorder_level'],
                    'inventory_type' => $slug === 'consumables' ? $catalogFields['inventory_type'] : null,
                    'days_to_consume' => $slug === 'consumables' ? $catalogFields['days_to_consume'] : null,
                    'description' => $catalogFields['description'],
                    'property_class' => $slug === 'semi_expendable' ? $catalogFields['property_class'] : null,
                    'ppe_type' => $slug === 'ppe' ? $catalogFields['ppe_type'] : null,
                    'uacs_object_code_id' => in_array($slug, ['semi_expendable', 'ppe'], true)
                        ? $catalogFields['uacs_object_code_id']
                        : null,
                    'estimated_useful_life' => $slug === 'semi_expendable'
                        ? $catalogFields['estimated_useful_life']
                        : null,
                ]);
                $item->setRelation('category', $category);
                $item->save();

                $appliedUnitCost = null;
                if (($quantity ?? 0) >= 1) {
                    $this->openingBalanceService->setOpeningStock(
                        item: $item,
                        officeId: (int) $officeId,
                        quantity: (int) $quantity,
                        unitCost: $unitCost,
                        recordedBy: $recordedBy,
                    );
                    $appliedUnitCost = $unitCost ?? 0.0;
                }

                $existingByName->put($nameKey, $item);
                $result['created']++;
                $result['createdNames'][] = $name;
                $result['rows'][] = [
                    'status' => 'created',
                    'excel_row' => $excelRow,
                    'excel' => $excelSnapshot,
                    'actual' => $this->actualSnapshotFromItem($item, $appliedUnitCost),
                    'reason' => null,
                ];
            }
        });

        return $result;
    }

    /**
     * Fill only catalog fields that are blank on the existing item. Reorder point is never updated.
     *
     * @param  array<string, mixed>  $catalogFields
     * @return array<string, mixed>
     */
    protected function fillMissingCatalogFields(Item $item, array $catalogFields, string $slug): array
    {
        $patches = [];

        if ($slug === 'consumables') {
            if (blank($item->inventory_type) && filled($catalogFields['inventory_type'])) {
                $patches['inventory_type'] = $catalogFields['inventory_type'];
            }

            if ($item->days_to_consume === null && $catalogFields['days_to_consume'] !== null) {
                $patches['days_to_consume'] = $catalogFields['days_to_consume'];
            }
        }

        if ($slug === 'semi_expendable') {
            if (blank($item->property_class) && filled($catalogFields['property_class'])) {
                $patches['property_class'] = $catalogFields['property_class'];
            }

            if ($item->uacs_object_code_id === null && filled($catalogFields['uacs_object_code_id'])) {
                $patches['uacs_object_code_id'] = $catalogFields['uacs_object_code_id'];
            }

            if (blank($item->estimated_useful_life) && filled($catalogFields['estimated_useful_life'])) {
                $patches['estimated_useful_life'] = $catalogFields['estimated_useful_life'];
            }
        }

        if ($slug === 'ppe') {
            if (blank($item->ppe_type) && filled($catalogFields['ppe_type'])) {
                $patches['ppe_type'] = $catalogFields['ppe_type'];
            }

            if ($item->uacs_object_code_id === null && filled($catalogFields['uacs_object_code_id'])) {
                $patches['uacs_object_code_id'] = $catalogFields['uacs_object_code_id'];
            }
        }

        if (blank($item->description) && filled($catalogFields['description'])) {
            $patches['description'] = $catalogFields['description'];
        }

        if ($patches === []) {
            return [];
        }

        $item->fill($patches);
        $item->save();

        return $patches;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $excel
     * @param  array<string, mixed>  $actual
     */
    protected function pushCatalogUpdated(
        array &$result,
        int $excelRow,
        string $name,
        array $excel,
        array $actual,
    ): void {
        $result['updatedNames'][] = $name;
        $result['rows'][] = [
            'status' => 'updated',
            'excel_row' => $excelRow,
            'excel' => $excel,
            'actual' => $actual,
            'reason' => 'Filled blank catalog fields.',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{fields: array<string, mixed>, error: ?string}
     */
    protected function resolveCatalogFields(array $row, string $slug): array
    {
        $defaults = [
            'reorder_level' => 0,
            'inventory_type' => null,
            'inventory_type_label' => null,
            'days_to_consume' => null,
            'description' => null,
            'property_class' => null,
            'property_class_label' => null,
            'ppe_type' => null,
            'ppe_type_label' => null,
            'uacs_object_code_id' => null,
            'uacs_code' => null,
            'estimated_useful_life' => null,
        ];

        $reorderRaw = $row['reorder_level'] ?? null;
        $reorderLevel = $reorderRaw === null || $reorderRaw === '' ? 0 : (int) $reorderRaw;
        if ($reorderLevel < 0) {
            return [
                'fields' => $defaults,
                'error' => 'Reorder point must be 0 or greater.',
            ];
        }

        $description = filled($row['description'] ?? null) ? trim((string) $row['description']) : null;

        if ($slug === 'consumables') {
            $inventoryTypeRaw = filled($row['inventory_type'] ?? null) ? trim((string) $row['inventory_type']) : null;
            $inventoryType = $inventoryTypeRaw !== null ? ConsumableInventoryType::resolve($inventoryTypeRaw) : null;
            if ($inventoryTypeRaw !== null && $inventoryType === null) {
                return [
                    'fields' => $defaults,
                    'error' => 'Inventory type must include letters (e.g. Vehicle Maintenance Supply).',
                ];
            }

            $daysRaw = $row['days_to_consume'] ?? null;
            $daysToConsume = null;
            if ($daysRaw !== null && $daysRaw !== '') {
                if (! is_numeric($daysRaw) || (int) $daysRaw < 0) {
                    return [
                        'fields' => $defaults,
                        'error' => 'Days to consume must be 0 or greater.',
                    ];
                }

                $daysToConsume = (int) $daysRaw;
            }

            return [
                'fields' => [
                    ...$defaults,
                    'reorder_level' => $reorderLevel,
                    'inventory_type' => $inventoryType,
                    'inventory_type_label' => $inventoryTypeRaw ?? ConsumableInventoryType::label($inventoryType),
                    'days_to_consume' => $daysToConsume,
                    'description' => $description,
                ],
                'error' => null,
            ];
        }

        if ($slug === 'semi_expendable') {
            $propertyClassRaw = filled($row['property_class'] ?? null) ? trim((string) $row['property_class']) : null;
            $propertyClass = $propertyClassRaw !== null ? ItemPropertyClass::resolve($propertyClassRaw) : null;
            if ($propertyClassRaw !== null && $propertyClass === null) {
                return [
                    'fields' => $defaults,
                    'error' => 'Property class must be an official COA label (e.g. Office Equipment).',
                ];
            }

            $uacsRaw = filled($row['uacs_object_code'] ?? null) ? trim((string) $row['uacs_object_code']) : null;
            $uacsId = $uacsRaw !== null ? $this->resolveUacsObjectCodeId($uacsRaw) : null;
            if ($uacsRaw !== null && $uacsId === null) {
                return [
                    'fields' => $defaults,
                    'error' => 'UACS object code was not found. Use an active code such as 106-03.',
                ];
            }

            $eulRaw = filled($row['estimated_useful_life'] ?? null) ? trim((string) $row['estimated_useful_life']) : null;
            if ($eulRaw !== null) {
                try {
                    SemiExpendableUsefulLife::assertEligibleForSemi($eulRaw);
                } catch (ValidationException $exception) {
                    return [
                        'fields' => $defaults,
                        'error' => $exception->validator->errors()->first('estimated_useful_life')
                            ?: 'Invalid estimated useful life.',
                    ];
                }
            }

            return [
                'fields' => [
                    ...$defaults,
                    'reorder_level' => $reorderLevel,
                    'description' => $description,
                    'property_class' => $propertyClass,
                    'property_class_label' => $propertyClassRaw ?? ItemPropertyClass::label($propertyClass),
                    'uacs_object_code_id' => $uacsId,
                    'uacs_code' => $uacsRaw ?? $this->uacsCodeForId($uacsId),
                    'estimated_useful_life' => $eulRaw,
                ],
                'error' => null,
            ];
        }

        $ppeTypeRaw = filled($row['ppe_type'] ?? null) ? trim((string) $row['ppe_type']) : null;
        $ppeType = $ppeTypeRaw !== null ? PpePropertyType::resolve($ppeTypeRaw) : null;
        if ($ppeTypeRaw !== null && $ppeType === null) {
            return [
                'fields' => $defaults,
                'error' => 'Type of PPE must be an official COA label (e.g. Office Equipment).',
            ];
        }

        $uacsRaw = filled($row['uacs_object_code'] ?? null) ? trim((string) $row['uacs_object_code']) : null;
        $uacsId = $uacsRaw !== null ? $this->resolveUacsObjectCodeId($uacsRaw) : null;
        if ($uacsRaw !== null && $uacsId === null) {
            return [
                'fields' => $defaults,
                'error' => 'UACS object code was not found. Use an active code such as 106-03.',
            ];
        }

        return [
            'fields' => [
                ...$defaults,
                'reorder_level' => $reorderLevel,
                'description' => $description,
                'ppe_type' => $ppeType,
                'ppe_type_label' => $ppeTypeRaw ?? PpePropertyType::label($ppeType),
                'uacs_object_code_id' => $uacsId,
                'uacs_code' => $uacsRaw ?? $this->uacsCodeForId($uacsId),
            ],
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $catalogFields
     */
    protected function validateRequiredForCreate(string $slug, array $catalogFields): ?string
    {
        if ($slug === 'semi_expendable') {
            if (blank($catalogFields['property_class'])) {
                return 'Property class is required.';
            }

            if (blank($catalogFields['uacs_object_code_id'])) {
                return 'UACS object code is required.';
            }

            if (blank($catalogFields['estimated_useful_life'])) {
                return 'Estimated useful life is required.';
            }
        }

        if ($slug === 'ppe') {
            if (blank($catalogFields['ppe_type'])) {
                return 'Type of PPE is required.';
            }

            if (blank($catalogFields['uacs_object_code_id'])) {
                return 'UACS object code is required.';
            }
        }

        return null;
    }

    protected function validateStartingStockCost(string $slug, ?int $quantity, ?float $unitCost): ?string
    {
        if ($quantity === null || $quantity < 1) {
            return null;
        }

        if (in_array($slug, ['ppe', 'semi_expendable'], true) && $unitCost === null) {
            return 'Unit cost is required when setting starting stock.';
        }

        try {
            if ($slug === 'ppe') {
                PpeValueCategory::assertMinimumForPpe($unitCost);
            }

            if ($slug === 'semi_expendable') {
                SemiExpendableValueCategory::assertWithinSemiCap($unitCost);
            }
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->first()
                ?: 'Unit cost is not valid for this category.';
        }

        return null;
    }

    protected function resolveUacsObjectCodeId(?string $raw): ?int
    {
        if (blank($raw)) {
            return null;
        }

        $code = trim((string) $raw);

        $uacs = UacsObjectCode::query()->active()->where('code', $code)->first();
        if ($uacs !== null) {
            return $uacs->id;
        }

        $digitsOnly = preg_replace('/\D/', '', $code) ?? '';
        if ($digitsOnly === '') {
            return null;
        }

        $match = UacsObjectCode::query()
            ->active()
            ->get(['id', 'code'])
            ->first(fn (UacsObjectCode $record): bool => preg_replace('/\D/', '', (string) $record->code) === $digitsOnly);

        return $match?->id;
    }

    protected function uacsCodeForId(?int $uacsId): ?string
    {
        if ($uacsId === null) {
            return null;
        }

        return UacsObjectCode::query()->whereKey($uacsId)->value('code');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{unit_cost: ?float, error: ?string}
     */
    protected function resolveUnitCost(array $row): array
    {
        $raw = $row['unit_cost'] ?? null;

        if ($raw === null || $raw === '') {
            return [
                'unit_cost' => null,
                'error' => null,
            ];
        }

        if (! is_numeric($raw)) {
            return [
                'unit_cost' => null,
                'error' => 'Unit cost must be a number of 0 or greater.',
            ];
        }

        $unitCost = round((float) $raw, 2);
        if ($unitCost < 0) {
            return [
                'unit_cost' => null,
                'error' => 'Unit cost must be a number of 0 or greater.',
            ];
        }

        return [
            'unit_cost' => $unitCost,
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function rawUnitCostFromRow(array $row): ?float
    {
        $raw = $row['unit_cost'] ?? null;
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return null;
        }

        return round((float) $raw, 2);
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array<string, mixed>
     */
    protected function actualSnapshot(
        string $base,
        ?string $sub,
        string $unit,
        string $name,
        array $catalog,
        ?float $unitCost = null,
    ): array {
        return [
            'base' => $base,
            'sub' => $sub,
            'unit' => $unit,
            'name' => $name,
            'reorder_level' => $catalog['reorder_level'],
            'inventory_type' => $catalog['inventory_type'] ?? null,
            'inventory_type_label' => $catalog['inventory_type_label'] ?? ConsumableInventoryType::label($catalog['inventory_type'] ?? null),
            'days_to_consume' => $catalog['days_to_consume'] ?? null,
            'description' => $catalog['description'] ?? null,
            'property_class' => $catalog['property_class'] ?? null,
            'property_class_label' => $catalog['property_class_label'] ?? ItemPropertyClass::label($catalog['property_class'] ?? null),
            'ppe_type' => $catalog['ppe_type'] ?? null,
            'ppe_type_label' => $catalog['ppe_type_label'] ?? PpePropertyType::label($catalog['ppe_type'] ?? null),
            'uacs_code' => $catalog['uacs_code'] ?? null,
            'estimated_useful_life' => $catalog['estimated_useful_life'] ?? null,
            'unit_cost' => $unitCost,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function actualSnapshotFromItem(Item $item, ?float $unitCost = null): array
    {
        return [
            'base' => (string) $item->base_name,
            'sub' => filled($item->sub_item) ? (string) $item->sub_item : null,
            'unit' => (string) $item->unit,
            'name' => (string) $item->name,
            'reorder_level' => (int) ($item->reorder_level ?? 0),
            'inventory_type' => $item->inventory_type,
            'inventory_type_label' => ConsumableInventoryType::label($item->inventory_type),
            'days_to_consume' => $item->days_to_consume !== null ? (int) $item->days_to_consume : null,
            'description' => filled($item->description) ? (string) $item->description : null,
            'property_class' => $item->property_class,
            'property_class_label' => ItemPropertyClass::label($item->property_class),
            'ppe_type' => $item->ppe_type,
            'ppe_type_label' => PpePropertyType::label($item->ppe_type),
            'uacs_code' => $this->uacsCodeForId($item->uacs_object_code_id),
            'estimated_useful_life' => filled($item->estimated_useful_life) ? (string) $item->estimated_useful_life : null,
            'unit_cost' => $unitCost,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $catalog
     * @return array<string, mixed>
     */
    protected function excelSnapshot(
        array $row,
        string $fallbackBase,
        ?string $fallbackSub,
        string $fallbackUnit,
        ?int $quantity,
        ?string $itemName,
        array $catalog,
        ?float $unitCost = null,
    ): array {
        $excelBase = trim((string) ($row['excel_base_name'] ?? $fallbackBase));
        $excelSub = array_key_exists('excel_sub_item', $row)
            ? (filled($row['excel_sub_item']) ? trim((string) $row['excel_sub_item']) : null)
            : $fallbackSub;
        $excelUnit = trim((string) ($row['excel_unit'] ?? $fallbackUnit));
        $inventoryTypeRaw = filled($row['inventory_type'] ?? null) ? trim((string) $row['inventory_type']) : null;
        $propertyClassRaw = filled($row['property_class'] ?? null) ? trim((string) $row['property_class']) : null;
        $ppeTypeRaw = filled($row['ppe_type'] ?? null) ? trim((string) $row['ppe_type']) : null;
        $uacsRaw = filled($row['uacs_object_code'] ?? null) ? trim((string) $row['uacs_object_code']) : null;

        return [
            'base' => $excelBase,
            'sub' => $excelSub,
            'unit' => $excelUnit,
            'qty' => $quantity,
            'item_name' => $itemName,
            'reorder_level' => $catalog['reorder_level'],
            'inventory_type' => $catalog['inventory_type'] ?? null,
            'inventory_type_label' => $inventoryTypeRaw ?? ($catalog['inventory_type_label'] ?? ConsumableInventoryType::label($catalog['inventory_type'] ?? null)),
            'days_to_consume' => $catalog['days_to_consume'] ?? null,
            'description' => $catalog['description'] ?? null,
            'property_class' => $catalog['property_class'] ?? null,
            'property_class_label' => $propertyClassRaw ?? ($catalog['property_class_label'] ?? ItemPropertyClass::label($catalog['property_class'] ?? null)),
            'ppe_type' => $catalog['ppe_type'] ?? null,
            'ppe_type_label' => $ppeTypeRaw ?? ($catalog['ppe_type_label'] ?? PpePropertyType::label($catalog['ppe_type'] ?? null)),
            'uacs_code' => $uacsRaw ?? ($catalog['uacs_code'] ?? null),
            'estimated_useful_life' => $catalog['estimated_useful_life'] ?? null,
            'unit_cost' => $unitCost,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array{base: string, sub: ?string, unit: string, qty: ?int, item_name: ?string}  $excel
     * @param  array{base: string, sub: ?string, unit: string, name: string}|null  $actual
     */
    protected function pushInvalid(
        array &$result,
        int $excelRow,
        string $name,
        string $reason,
        array $excel,
        ?array $actual = null,
    ): void {
        $result['invalid'][] = [
            'row' => $excelRow,
            'name' => $name,
            'reason' => $reason,
        ];
        $result['rows'][] = [
            'status' => 'invalid',
            'excel_row' => $excelRow,
            'excel' => $excel,
            'actual' => $actual,
            'reason' => $reason,
        ];
    }
}
