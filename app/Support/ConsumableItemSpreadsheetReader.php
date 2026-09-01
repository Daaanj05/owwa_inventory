<?php

namespace App\Support;

use App\Models\Item;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ConsumableItemSpreadsheetReader
{
    /**
     * @return list<array{
     *     row: int,
     *     base_name: string,
     *     sub_item: ?string,
     *     unit: string,
     *     opening_quantity: ?int,
     *     name: string,
     *     item_name: ?string
     * }>
     */
    public function read(string $absolutePath): array
    {
        return $this->readWithMetadata($absolutePath)['rows'];
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     headerMap: array<string, int>|null
     * }
     */
    public function readWithMetadata(string $absolutePath): array
    {
        PhpExtensionGuard::ensureZipArchive();

        $spreadsheet = IOFactory::load($absolutePath);

        try {
            return $this->readSheetWithMetadata($spreadsheet->getActiveSheet());
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /**
     * @return list<string>
     */
    public static function distinctiveCategoriesFromHeaderMap(?array $headerMap): array
    {
        if ($headerMap === null || $headerMap === []) {
            return [];
        }

        $categories = [];

        if (isset($headerMap['inventory_type']) || isset($headerMap['days_to_consume'])) {
            $categories[] = 'consumables';
        }

        if (isset($headerMap['property_class']) || isset($headerMap['estimated_useful_life'])) {
            $categories[] = 'semi_expendable';
        }

        if (isset($headerMap['ppe_type'])) {
            $categories[] = 'ppe';
        }

        return array_values(array_unique($categories));
    }

    public static function categoryImportLabel(string $slug): string
    {
        return match ($slug) {
            'semi_expendable' => 'Semi-expendable',
            'ppe' => 'PPE',
            default => 'Consumables',
        };
    }

    public static function sampleSpreadsheetForSlug(string $slug): Spreadsheet
    {
        return match ($slug) {
            'semi_expendable' => self::sampleSemiExpendableSpreadsheet(),
            'ppe' => self::samplePpeSpreadsheet(),
            default => self::sampleSpreadsheet(),
        };
    }

    public static function sampleFilenameForSlug(string $slug): string
    {
        return match ($slug) {
            'semi_expendable' => 'semi-expendable-items-import-sample.xlsx',
            'ppe' => 'ppe-items-import-sample.xlsx',
            default => 'consumable-items-import-sample.xlsx',
        };
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     headerMap: array<string, int>|null
     * }
     */
    public function readSheetWithMetadata(Worksheet $sheet): array
    {
        $rows = $sheet->toArray(null, true, true, false);
        if ($rows === []) {
            return [
                'rows' => [],
                'headerMap' => null,
            ];
        }

        [$headerMap, $dataStart] = $this->resolveHeaderAndDataStart($rows);

        $parsed = [];

        for ($i = $dataStart; $i < count($rows); $i++) {
            $cells = $rows[$i];
            if (! $this->rowHasValues($cells)) {
                continue;
            }

            $excelRow = $i + 1;
            $mapped = $headerMap !== null
                ? $this->mapByHeader($cells, $headerMap)
                : $this->mapPositional($cells);

            if ($mapped === null) {
                continue;
            }

            $excelBase = trim((string) ($mapped['base_name'] ?? ''));
            $excelSub = filled($mapped['sub_item'] ?? null) ? trim((string) $mapped['sub_item']) : null;
            $itemName = filled($mapped['item_name'] ?? null) ? trim((string) $mapped['item_name']) : null;
            $excelUnit = trim((string) ($mapped['unit'] ?? ''));
            $quantity = $mapped['opening_quantity'];

            [$baseName, $subItem] = $this->sanitizeBaseAndSub($excelBase, $excelSub, $itemName);

            if ($baseName === '' && $subItem === null && $excelUnit === '' && $quantity === null) {
                continue;
            }

            $unit = $excelUnit !== '' ? $excelUnit : 'piece';
            $name = Item::mergeDisplayName($baseName, $subItem);

            $parsed[] = [
                'row' => $excelRow,
                'base_name' => $baseName,
                'sub_item' => $subItem,
                'unit' => $unit,
                'opening_quantity' => $quantity,
                'name' => $name,
                'item_name' => $itemName,
                'excel_base_name' => $excelBase,
                'excel_sub_item' => $excelSub,
                'excel_unit' => $excelUnit !== '' ? $excelUnit : $unit,
                'reorder_level' => $mapped['reorder_level'] ?? null,
                'inventory_type' => filled($mapped['inventory_type'] ?? null) ? (string) $mapped['inventory_type'] : null,
                'days_to_consume' => $mapped['days_to_consume'] ?? null,
                'description' => filled($mapped['description'] ?? null) ? (string) $mapped['description'] : null,
                'unit_cost' => $mapped['unit_cost'] ?? null,
                'property_class' => filled($mapped['property_class'] ?? null) ? (string) $mapped['property_class'] : null,
                'ppe_type' => filled($mapped['ppe_type'] ?? null) ? (string) $mapped['ppe_type'] : null,
                'uacs_object_code' => filled($mapped['uacs_object_code'] ?? null) ? (string) $mapped['uacs_object_code'] : null,
                'estimated_useful_life' => filled($mapped['estimated_useful_life'] ?? null) ? (string) $mapped['estimated_useful_life'] : null,
            ];
        }

        return [
            'rows' => $parsed,
            'headerMap' => $headerMap,
        ];
    }

    public static function sampleSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Consumables');

        $headers = [
            'Item name',
            'Base item',
            'Sub-item',
            'Unit',
            'Quantity',
            'Reorder point',
            'Inventory type',
            'Days to consume',
            'Description',
            'Unit cost',
        ];

        $rows = [
            $headers,
            [
                'Bondpaper, A4',
                'Bondpaper',
                'A4',
                'ream',
                40,
                10,
                ConsumableInventoryType::label(ConsumableInventoryType::OfficeSupplies),
                '',
                'Short bond paper for office use',
                250.00,
            ],
            [
                'Official Receipt Form',
                'Official Receipt Form',
                '',
                'pad',
                5,
                2,
                ConsumableInventoryType::label(ConsumableInventoryType::AccountableForms),
                '',
                'Accountable form booklet',
                85.50,
            ],
            [
                'Alcohol, 500ml',
                'Alcohol',
                '500ml',
                'bottle',
                147,
                20,
                ConsumableInventoryType::label(ConsumableInventoryType::MedicalDentalLaboratory),
                30,
                'Isopropyl alcohol for clinic use',
                45.00,
            ],
            [
                'Bottled Water, 350ml',
                'Bottled Water',
                '350ml',
                'bottle',
                100,
                24,
                ConsumableInventoryType::label(ConsumableInventoryType::FoodSupplies),
                14,
                'Drinking water for meetings',
                12.00,
            ],
            [
                'Detergent, Powder',
                'Detergent',
                'Powder',
                'pack',
                20,
                4,
                ConsumableInventoryType::label(ConsumableInventoryType::JanitorialSupplies),
                '',
                'Cleaning detergent for office restrooms',
                55.00,
            ],
            [
                'Trash Bag, Large',
                'Trash Bag',
                'Large',
                'pack',
                30,
                5,
                ConsumableInventoryType::label(ConsumableInventoryType::OtherSupplies),
                '',
                'General other supply',
                75.00,
            ],
        ];

        $sheet->fromArray($rows);

        $lastRow = count($rows) - 1;
        self::styleSampleSheet($sheet, count($headers), $lastRow);

        // Match the sample workbook column widths from the Import UI screenshot.
        $columnWidths = [
            'A' => 22,
            'B' => 22,
            'C' => 12,
            'D' => 10,
            'E' => 12,
            'F' => 14,
            'G' => 55,
            'H' => 16,
            'I' => 36,
            'J' => 12,
        ];

        foreach ($columnWidths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        return $spreadsheet;
    }

    public static function sampleSemiExpendableSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Semi-expendable');

        $headers = [
            'Item name',
            'Base item',
            'Sub-item',
            'Unit',
            'Quantity',
            'Reorder point',
            'Property class',
            'UACS object code',
            'Estimated useful life',
            'Description',
            'Unit cost',
        ];

        /** @var array<string, array{0: string, 1: ?string, 2: string, 3: string, 4: string, 5: int, 6: float}> $samples */
        $samples = [
            ItemPropertyClass::InformationTechnology => ['Laptop', 'Standard', 'unit', '106-01', 'Sample IT equipment', 36, 45000.00],
            ItemPropertyClass::FurnitureFixtures => ['Office Desk', 'Executive', 'piece', '106-02', 'Executive office desk', 60, 8500.00],
            ItemPropertyClass::OfficeEquipment => ['Office Chair', 'Ergonomic', 'piece', '106-03', 'Ergonomic office chair', 36, 12500.00],
            ItemPropertyClass::CommunicationEquipment => ['VoIP Phone', 'Desk', 'unit', '106-04', 'Desk VoIP telephone', 36, 15000.00],
            ItemPropertyClass::Appliances => ['Air Conditioner', '2HP', 'unit', '106-05', 'Split-type air conditioner', 60, 35000.00],
            ItemPropertyClass::MachineryEquipment => ['Drill Press', 'Bench', 'unit', '106-06', 'Bench drill press', 48, 28000.00],
            ItemPropertyClass::TransportationEquipment => ['Electric Cart', '4-Seater', 'unit', '106-07', 'Electric utility cart', 60, 42000.00],
            ItemPropertyClass::MedicalEquipment => ['Patient Monitor', 'Portable', 'unit', '106-08', 'Portable patient monitor', 48, 38000.00],
        ];

        $rows = [$headers];

        foreach (ItemPropertyClass::options() as $key => $label) {
            [$base, $sub, $unit, $uacs, $description, $eul, $cost] = $samples[$key];
            $rows[] = [
                Item::mergeDisplayName($base, $sub),
                $base,
                $sub,
                $unit,
                0,
                0,
                $label,
                $uacs,
                $eul,
                $description,
                $cost,
            ];
        }

        $sheet->fromArray($rows);
        self::styleSampleSheet($sheet, count($headers), count($rows));

        $columnWidths = [
            'A' => 28,
            'B' => 22,
            'C' => 14,
            'D' => 10,
            'E' => 12,
            'F' => 14,
            'G' => 34,
            'H' => 18,
            'I' => 20,
            'J' => 32,
            'K' => 12,
        ];

        foreach ($columnWidths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        return $spreadsheet;
    }

    public static function samplePpeSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PPE');

        $headers = [
            'Item name',
            'Base item',
            'Sub-item',
            'Unit',
            'Quantity',
            'Reorder point',
            'Type of PPE',
            'UACS object code',
            'Description',
            'Unit cost',
        ];

        /** @var array<string, array{0: string, 1: ?string, 2: string, 3: string, 4: string, 5: float}> $samples */
        $samples = [
            PpePropertyType::Land => ['Land Parcel', 'Regional Site', 'lot', '106', 'Sample land asset', 2500000.00],
            PpePropertyType::LandImprovements => ['Parking Lot', 'Paved', 'lot', '106', 'Paved parking improvement', 850000.00],
            PpePropertyType::InfrastructureAssets => ['Road Section', 'Access', 'unit', '106', 'Access road section', 1200000.00],
            PpePropertyType::BuildingsOtherStructures => ['Office Wing', 'Annex', 'unit', '106', 'Office building annex', 8500000.00],
            PpePropertyType::MachineryEquipment => ['Industrial Generator', '500kVA', 'unit', '106-06', 'Standby generator set', 750000.00],
            PpePropertyType::HeavyEquipment => ['Bulldozer', 'Crawler', 'unit', '106-06', 'Crawler bulldozer', 4500000.00],
            PpePropertyType::TechnicalScientificEquipment => ['Spectrometer', 'Lab', 'unit', '106-01', 'Laboratory spectrometer', 950000.00],
            PpePropertyType::OfficeEquipment => ['Desktop Computer', 'Brand X', 'unit', '106-03', 'Brand X desktop computer', 75000.00],
            PpePropertyType::TransportationEquipment => ['Company Van', '15-Seater', 'unit', '106-07', 'Passenger service van', 1800000.00],
            PpePropertyType::MotorVehicle => ['Service Vehicle', 'Pickup', 'unit', '106-07', 'Pickup service vehicle', 950000.00],
            PpePropertyType::FurnitureFixturesBooks => ['Library Set', 'Reference', 'set', '106-02', 'Reference library set', 65000.00],
            PpePropertyType::OtherPpe => ['Miscellaneous Asset', 'General', 'unit', '106-03', 'Other capital asset', 80000.00],
        ];

        $rows = [$headers];

        foreach (PpePropertyType::options() as $key => $label) {
            [$base, $sub, $unit, $uacs, $description, $cost] = $samples[$key];
            $rows[] = [
                Item::mergeDisplayName($base, $sub),
                $base,
                $sub,
                $unit,
                0,
                0,
                $label,
                $uacs,
                $description,
                $cost,
            ];
        }

        $sheet->fromArray($rows);
        self::styleSampleSheet($sheet, count($headers), count($rows));

        $columnWidths = [
            'A' => 30,
            'B' => 24,
            'C' => 14,
            'D' => 10,
            'E' => 12,
            'F' => 14,
            'G' => 38,
            'H' => 18,
            'I' => 34,
            'J' => 14,
        ];

        foreach ($columnWidths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        return $spreadsheet;
    }

    protected static function styleSampleSheet(Worksheet $sheet, int $columnCount, int $lastRow): void
    {
        $lastColumn = self::columnLetter($columnCount);
        $range = "A1:{$lastColumn}{$lastRow}";

        $sheet->getStyle("A1:{$lastColumn}1")
            ->getFont()
            ->setBold(true);

        $sheet->getStyle($range)
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_CENTER);
    }

    protected static function columnLetter(int $columnIndex): string
    {
        $letter = '';
        while ($columnIndex > 0) {
            $columnIndex--;
            $letter = chr(65 + ($columnIndex % 26)).$letter;
            $columnIndex = intdiv($columnIndex, 26);
        }

        return $letter;
    }

    /**
     * Normalize catalog names for duplicate matching (commas/spaces).
     */
    public static function normalizeNameKey(string $name): string
    {
        $normalized = Str::of($name)
            ->lower()
            ->replace(',', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        return $normalized;
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     * @return array{0: array{base?: int, sub?: int, item_name?: int, unit?: int, qty?: int}|null, 1: int}
     */
    protected function resolveHeaderAndDataStart(array $rows): array
    {
        $scanLimit = min(count($rows), 10);

        for ($i = 0; $i < $scanLimit; $i++) {
            if (! $this->rowHasValues($rows[$i])) {
                continue;
            }

            $headerMap = $this->detectHeaderMap($rows[$i]);
            if ($headerMap !== null) {
                return [$headerMap, $i + 1];
            }
        }

        $firstNonEmptyIndex = 0;
        foreach ($rows as $index => $cells) {
            if ($this->rowHasValues($cells)) {
                $firstNonEmptyIndex = $index;
                break;
            }
        }

        return [null, $firstNonEmptyIndex];
    }

    /**
     * @param  array<int, mixed>  $cells
     * @return array{base?: int, sub?: int, item_name?: int, unit?: int, qty?: int}|null
     */
    protected function detectHeaderMap(array $cells): ?array
    {
        $map = [];

        foreach ($cells as $index => $value) {
            $alias = $this->normalizeHeaderAlias($value);
            if ($alias === null) {
                continue;
            }

            $map[$alias] = (int) $index;
        }

        if (count($map) < 2) {
            return null;
        }

        return $map;
    }

    protected function normalizeHeaderAlias(mixed $value): ?string
    {
        $label = Str::of((string) $value)->lower()->replaceMatches('/\s+/', ' ')->trim()->toString();

        return match (true) {
            in_array($label, ['base item', 'base', 'base_name', 'basename'], true) => 'base',
            in_array($label, ['sub-item', 'sub item', 'sub_item', 'subitem', 'sub'], true) => 'sub',
            in_array($label, ['item name', 'item_name', 'name', 'item'], true) => 'item_name',
            in_array($label, ['unit', 'measurement unit', 'uom', 'unit of measure'], true) => 'unit',
            in_array($label, ['quantity', 'qty', 'stocks', 'stock', 'starting qty', 'opening quantity'], true) => 'qty',
            in_array($label, ['reorder point', 'reorder_point', 'reorder level', 'reorder_level', 'reorder'], true) => 'reorder',
            in_array($label, ['inventory type', 'inventory_type', 'inventory'], true) => 'inventory_type',
            in_array($label, ['days to consume', 'days_to_consume', 'days consume'], true) => 'days_to_consume',
            in_array($label, ['property class', 'property_class', 'property'], true) => 'property_class',
            in_array($label, ['type of ppe', 'ppe type', 'ppe_type', 'type of property plant and equipment'], true) => 'ppe_type',
            in_array($label, ['uacs object code', 'uacs_object_code', 'uacs', 'object code', 'uacs code'], true) => 'uacs_object_code',
            in_array($label, ['estimated useful life', 'estimated_useful_life', 'useful life', 'eul'], true) => 'estimated_useful_life',
            in_array($label, ['description', 'remarks', 'notes'], true) => 'description',
            in_array($label, ['unit cost', 'unit_cost', 'cost', 'unit price', 'unit_price', 'price'], true) => 'unit_cost',
            default => null,
        };
    }

    /**
     * @param  array<int, mixed>  $cells
     * @param  array{base?: int, sub?: int, item_name?: int, unit?: int, qty?: int}  $map
     * @return array{base_name: string, sub_item: ?string, unit: string, opening_quantity: ?int, item_name: ?string}|null
     */
    protected function mapByHeader(array $cells, array $map): ?array
    {
        $base = isset($map['base']) ? trim((string) ($cells[$map['base']] ?? '')) : '';
        $itemName = isset($map['item_name']) ? trim((string) ($cells[$map['item_name']] ?? '')) : '';
        $sub = isset($map['sub']) ? trim((string) ($cells[$map['sub']] ?? '')) : '';
        $unit = isset($map['unit']) ? trim((string) ($cells[$map['unit']] ?? '')) : '';
        $qtyRaw = isset($map['qty']) ? ($cells[$map['qty']] ?? null) : null;
        $reorderRaw = isset($map['reorder']) ? ($cells[$map['reorder']] ?? null) : null;
        $inventoryType = isset($map['inventory_type']) ? trim((string) ($cells[$map['inventory_type']] ?? '')) : '';
        $daysRaw = isset($map['days_to_consume']) ? ($cells[$map['days_to_consume']] ?? null) : null;
        $propertyClass = isset($map['property_class']) ? trim((string) ($cells[$map['property_class']] ?? '')) : '';
        $ppeType = isset($map['ppe_type']) ? trim((string) ($cells[$map['ppe_type']] ?? '')) : '';
        $uacsCode = isset($map['uacs_object_code']) ? trim((string) ($cells[$map['uacs_object_code']] ?? '')) : '';
        $eulRaw = isset($map['estimated_useful_life']) ? ($cells[$map['estimated_useful_life']] ?? null) : null;
        $description = isset($map['description']) ? trim((string) ($cells[$map['description']] ?? '')) : '';
        $unitCostRaw = isset($map['unit_cost']) ? ($cells[$map['unit_cost']] ?? null) : null;

        // Prefer Base item when present. Item name is never a second catalog field.
        if ($base === '' && $itemName !== '') {
            $base = $itemName;
            $sub = '';
        }

        return [
            'base_name' => $base,
            'sub_item' => $sub !== '' ? $sub : null,
            'unit' => $unit,
            'opening_quantity' => $this->parseQuantity($qtyRaw),
            'item_name' => $itemName !== '' ? $itemName : null,
            'reorder_level' => $this->parseQuantity($reorderRaw),
            'inventory_type' => $inventoryType !== '' ? $inventoryType : null,
            'days_to_consume' => $this->parseQuantity($daysRaw),
            'property_class' => $propertyClass !== '' ? $propertyClass : null,
            'ppe_type' => $ppeType !== '' ? $ppeType : null,
            'uacs_object_code' => $uacsCode !== '' ? $uacsCode : null,
            'estimated_useful_life' => filled($eulRaw) ? trim((string) $eulRaw) : null,
            'description' => $description !== '' ? $description : null,
            'unit_cost' => $this->parseUnitCost($unitCostRaw),
        ];
    }

    /**
     * @param  array<int, mixed>  $cells
     * @return array{base_name: string, sub_item: ?string, unit: string, opening_quantity: ?int, item_name: ?string}|null
     */
    protected function mapPositional(array $cells): ?array
    {
        $values = [];
        foreach ($cells as $cell) {
            if ($cell === null || trim((string) $cell) === '') {
                $values[] = '';
            } else {
                $values[] = trim((string) $cell);
            }
        }

        while ($values !== [] && end($values) === '') {
            array_pop($values);
        }

        // Client sheets often have a leading row-number column (1, 2, 3…).
        if ($values !== [] && $this->isRowIndexValue($values[0])) {
            array_shift($values);
        }

        $count = count($values);

        if ($count >= 5) {
            $itemName = $values[0];
            $base = $values[1];
            $sub = $values[2];
            $unit = $values[3];
            $qty = $values[4];

            if ($base !== '') {
                return [
                    'base_name' => $base,
                    'sub_item' => $sub !== '' ? $sub : null,
                    'unit' => $unit,
                    'opening_quantity' => $this->parseQuantity($qty),
                    'item_name' => $itemName !== '' ? $itemName : null,
                ];
            }

            return [
                'base_name' => $itemName,
                'sub_item' => null,
                'unit' => $unit,
                'opening_quantity' => $this->parseQuantity($qty),
                'item_name' => $itemName !== '' ? $itemName : null,
            ];
        }

        if ($count === 4) {
            return [
                'base_name' => $values[0],
                'sub_item' => $values[1] !== '' ? $values[1] : null,
                'unit' => $values[2],
                'opening_quantity' => $this->parseQuantity($values[3]),
                'item_name' => null,
            ];
        }

        if ($count === 3) {
            return [
                'base_name' => $values[0],
                'sub_item' => null,
                'unit' => $values[1],
                'opening_quantity' => $this->parseQuantity($values[2]),
                'item_name' => $values[0] !== '' ? $values[0] : null,
            ];
        }

        if ($count === 2) {
            return [
                'base_name' => $values[0],
                'sub_item' => null,
                'unit' => 'piece',
                'opening_quantity' => $this->parseQuantity($values[1]),
                'item_name' => $values[0] !== '' ? $values[0] : null,
            ];
        }

        if ($count === 1) {
            return [
                'base_name' => $values[0],
                'sub_item' => null,
                'unit' => 'piece',
                'opening_quantity' => null,
                'item_name' => $values[0] !== '' ? $values[0] : null,
            ];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    protected function sanitizeBaseAndSub(string $baseName, ?string $subItem, ?string $itemName): array
    {
        if ($baseName === '' && filled($itemName)) {
            return [trim($itemName), null];
        }

        if ($subItem === null || $subItem === '') {
            return [$baseName, null];
        }

        // Avoid "Screwdriver Set 9 Way Screwdriver Set 9 Way" when base and sub are identical.
        if (mb_strtolower($subItem) === mb_strtolower($baseName)) {
            return [$baseName, null];
        }

        // Avoid "Alcohol, Gal Alcohol" when sub is a fragment already inside base.
        if (str_contains(mb_strtolower($baseName), mb_strtolower($subItem))) {
            return [$baseName, null];
        }

        $merged = Item::mergeDisplayName($baseName, $subItem);
        if (filled($itemName) && self::normalizeNameKey($merged) === self::normalizeNameKey($itemName)) {
            return [$baseName, $subItem];
        }

        // If base already looks like the full item name, do not append sub again.
        if (filled($itemName) && self::normalizeNameKey($baseName) === self::normalizeNameKey($itemName)) {
            return [$baseName, null];
        }

        return [$baseName, $subItem];
    }

    protected function isRowIndexValue(mixed $value): bool
    {
        $text = trim((string) $value);

        return $text !== '' && preg_match('/^\d{1,4}$/', $text) === 1;
    }

    protected function parseQuantity(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $digits = preg_replace('/[^\d\-]/', '', (string) $value) ?? '';

        if ($digits === '' || $digits === '-') {
            return null;
        }

        return (int) $digits;
    }

    protected function parseUnitCost(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        $normalized = preg_replace('/[^\d.\-]/', '', (string) $value) ?? '';

        if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === '-.') {
            return null;
        }

        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    protected function rowHasValues(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return true;
            }
        }

        return false;
    }
}
