<?php

namespace Tests\Feature;

use App\Filament\Resources\Items\Actions\ItemImportAction;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\StockOpeningBalance;
use App\Models\UacsObjectCode;
use App\Models\User;
use App\Services\ImportConsumableItemsService;
use App\Services\InventoryStockService;
use App\Support\ConsumableInventoryType;
use App\Support\ConsumableItemSpreadsheetReader;
use App\Support\ItemPropertyClass;
use App\Support\PpePropertyType;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ItemImportConsumablesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_import_creates_item_from_base_sub_unit_qty_and_ignores_item_name_column(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $path = $this->writeSpreadsheet([
            ['Item name', 'Base item', 'Sub-item', 'Unit', 'Quantity'],
            ['Alcohol, 500ml', 'Alcohol', '500ml', 'bottle', 147],
            ['Correction Tape', 'Correction Tape', '', 'piece', 20],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(2, $result['created']);
        $this->assertDatabaseHas(Item::class, [
            'item_category_id' => $category->id,
            'base_name' => 'Alcohol',
            'sub_item' => '500ml',
            'name' => 'Alcohol 500ml',
            'unit' => 'bottle',
        ]);
        $this->assertDatabaseHas(Item::class, [
            'item_category_id' => $category->id,
            'base_name' => 'Correction Tape',
            'sub_item' => null,
            'name' => 'Correction Tape',
            'unit' => 'piece',
        ]);

        $alcohol = Item::query()->where('name', 'Alcohol 500ml')->first();
        $this->assertNotNull($alcohol);
        $this->assertDatabaseHas(StockOpeningBalance::class, [
            'item_id' => $alcohol->id,
            'office_id' => $office->id,
            'quantity' => 147,
        ]);
        $this->assertSame(147, app(InventoryStockService::class)->getStock($alcohol->id, $office->id));
        $this->assertSame(1, Item::query()->where('name', 'Alcohol 500ml')->count());

        $createdRows = array_values(array_filter(
            $result['rows'],
            fn (array $row): bool => ($row['status'] ?? '') === 'created',
        ));
        $this->assertCount(2, $createdRows);
        $this->assertSame('Alcohol', $createdRows[0]['excel']['base']);
        $this->assertSame('500ml', $createdRows[0]['excel']['sub']);
        $this->assertSame('bottle', $createdRows[0]['excel']['unit']);
        $this->assertSame('Alcohol', $createdRows[0]['actual']['base']);
        $this->assertSame('500ml', $createdRows[0]['actual']['sub']);
        $this->assertSame('bottle', $createdRows[0]['actual']['unit']);
    }

    public function test_import_maps_size_as_sub_item_with_leading_row_index_column(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $path = $this->writeSpreadsheet([
            ['', 'Item name', 'Base item', 'Sub-item', 'Unit', 'Quantity'],
            [7, 'Alcohol, 500ml', 'Alcohol', '500ml', 'bottle', 147],
            [8, 'Alcohol, Gal', 'Alcohol', 'Gal', 'Gal', 10],
            [9, 'Screwdriver Set 9 Way', 'Screwdriver Set 9 Way', 'Screwdriver Set 9 Way', 'set', 2],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(3, $result['created']);
        $this->assertSame([], $result['invalid']);
        $this->assertDatabaseHas(Item::class, [
            'base_name' => 'Alcohol',
            'sub_item' => '500ml',
            'name' => 'Alcohol 500ml',
            'unit' => 'bottle',
        ]);
        $this->assertDatabaseHas(Item::class, [
            'base_name' => 'Alcohol',
            'sub_item' => 'Gal',
            'name' => 'Alcohol Gal',
            'unit' => 'Gal',
        ]);
        $this->assertDatabaseHas(Item::class, [
            'base_name' => 'Screwdriver Set 9 Way',
            'sub_item' => null,
            'name' => 'Screwdriver Set 9 Way',
            'unit' => 'set',
        ]);
        $this->assertSame(0, Item::query()->where('unit', '500ml')->count());
    }

    public function test_headerless_four_column_treats_second_column_as_sub_item_not_unit(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $path = $this->writeSpreadsheet([
            ['Alcohol', '500ml', 'bottle', 147],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas(Item::class, [
            'base_name' => 'Alcohol',
            'sub_item' => '500ml',
            'name' => 'Alcohol 500ml',
            'unit' => 'bottle',
        ]);
    }

    public function test_headerless_three_column_full_name_creates_one_item(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $path = $this->writeSpreadsheet([
            ['Alcohol, 500ml', 'bottle', 147],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas(Item::class, [
            'item_category_id' => $category->id,
            'base_name' => 'Alcohol, 500ml',
            'sub_item' => null,
            'name' => 'Alcohol, 500ml',
            'unit' => 'bottle',
        ]);
        $this->assertSame(1, Item::query()->where('item_category_id', $category->id)->count());
    }

    public function test_existing_item_without_stock_gets_starting_quantity_only(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
            'unit' => 'ream',
        ]);

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity'],
            ['Bond Paper', 'A4', 'ream', 40],
            ['Folder', 'Long', 'piece', 10],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(1, $result['created']);
        $this->assertSame(['Bond Paper A4'], $result['stockFilled']);
        $this->assertSame(1, Item::query()->where('name', 'Bond Paper A4')->count());
        $this->assertDatabaseHas(StockOpeningBalance::class, [
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 40,
        ]);
        $this->assertSame(40, app(InventoryStockService::class)->getStock($item->id, $office->id));
    }

    public function test_existing_item_with_acquisition_is_skipped(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
            'unit' => 'ream',
        ]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-IMPORT-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'unit_cost' => 10,
            'acquisition_date' => now()->toDateString(),
            'recorded_by' => $user->id,
        ]);

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity'],
            ['Bond Paper', 'A4', 'ream', 99],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(0, $result['created']);
        $this->assertSame(['Bond Paper A4'], $result['skippedHasStock']);
        $this->assertDatabaseMissing(StockOpeningBalance::class, [
            'item_id' => $item->id,
        ]);
        $this->assertSame(1, Item::query()->where('name', 'Bond Paper A4')->count());
    }

    public function test_duplicate_name_in_file_is_skipped(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity'],
            ['Marker', 'Blue', 'piece', 5],
            ['Marker', 'Blue', 'piece', 9],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(1, $result['created']);
        $this->assertSame(['Marker Blue'], $result['skippedInFile']);
        $this->assertSame(1, Item::query()->where('name', 'Marker Blue')->count());
        $marker = Item::query()->where('name', 'Marker Blue')->first();
        $this->assertSame(5, app(InventoryStockService::class)->getStock($marker->id, $office->id));
    }

    public function test_invalid_unit_is_listed_and_not_created(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity'],
            ['Ink', 'Black', '500ml', 3],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(0, $result['created']);
        $this->assertCount(1, $result['invalid']);
        $this->assertSame('Ink Black', $result['invalid'][0]['name']);
        $this->assertDatabaseMissing(Item::class, [
            'name' => 'Ink Black',
        ]);
    }

    public function test_sample_spreadsheet_has_expected_headers(): void
    {
        $spreadsheet = ConsumableItemSpreadsheetReader::sampleSpreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Item name', $sheet->getCell('A1')->getValue());
        $this->assertSame('Base item', $sheet->getCell('B1')->getValue());
        $this->assertSame('Sub-item', $sheet->getCell('C1')->getValue());
        $this->assertSame('Unit', $sheet->getCell('D1')->getValue());
        $this->assertSame('Quantity', $sheet->getCell('E1')->getValue());
        $this->assertSame('Reorder point', $sheet->getCell('F1')->getValue());
        $this->assertSame('Inventory type', $sheet->getCell('G1')->getValue());
        $this->assertSame('Days to consume', $sheet->getCell('H1')->getValue());
        $this->assertSame('Description', $sheet->getCell('I1')->getValue());
        $this->assertSame('Unit cost', $sheet->getCell('J1')->getValue());

        $inventoryTypes = [];
        for ($row = 2; $row <= 7; $row++) {
            $inventoryTypes[] = (string) $sheet->getCell('G'.$row)->getValue();
        }

        $this->assertSame(array_values(ConsumableInventoryType::options()), $inventoryTypes);

        $this->assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('A1')->getAlignment()->getHorizontal());
        $this->assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('E2')->getAlignment()->getHorizontal());
        $this->assertSame(55.0, $sheet->getColumnDimension('G')->getWidth());
        $this->assertSame(36.0, $sheet->getColumnDimension('I')->getWidth());
        $this->assertSame(12.0, $sheet->getColumnDimension('J')->getWidth());
        $this->assertSame(10.0, $sheet->getColumnDimension('D')->getWidth());
        $this->assertSame(250.0, (float) $sheet->getCell('J2')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_import_reads_optional_catalog_columns_on_new_items(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $path = $this->writeSpreadsheet([
            [
                'Base item',
                'Sub-item',
                'Unit',
                'Quantity',
                'Reorder point',
                'Inventory type',
                'Days to consume',
                'Description',
            ],
            [
                'Bondpaper',
                'A4',
                'ream',
                25,
                10,
                'Office Supplies Inventory',
                45,
                'Short bond paper',
            ],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas(Item::class, [
            'name' => 'Bondpaper A4',
            'reorder_level' => 10,
            'inventory_type' => 'office_supplies',
            'days_to_consume' => 45,
            'description' => 'Short bond paper',
        ]);
    }

    public function test_import_applies_optional_unit_cost_to_starting_stock(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $path = $this->writeSpreadsheet([
            [
                'Base item',
                'Sub-item',
                'Unit',
                'Quantity',
                'Description',
                'Unit cost',
            ],
            [
                'Bondpaper',
                'A4',
                'ream',
                25,
                'Short bond paper',
                250.50,
            ],
            [
                'Marker',
                'Blue',
                'piece',
                10,
                '',
                '',
            ],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(2, $result['created']);

        $bondpaper = Item::query()->where('name', 'Bondpaper A4')->first();
        $this->assertNotNull($bondpaper);
        $this->assertDatabaseHas(StockOpeningBalance::class, [
            'item_id' => $bondpaper->id,
            'office_id' => $office->id,
            'quantity' => 25,
            'unit_cost' => 250.50,
        ]);

        $marker = Item::query()->where('name', 'Marker Blue')->first();
        $this->assertNotNull($marker);
        $this->assertDatabaseHas(StockOpeningBalance::class, [
            'item_id' => $marker->id,
            'office_id' => $office->id,
            'quantity' => 10,
            'unit_cost' => 0,
        ]);
    }

    public function test_negative_unit_cost_is_listed_and_not_created(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity', 'Unit cost'],
            ['Stapler', '', 'piece', 5, -1],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(0, $result['created']);
        $this->assertCount(1, $result['invalid']);
        $this->assertStringContainsString('Unit cost', $result['invalid'][0]['reason']);
        $this->assertDatabaseMissing(Item::class, ['name' => 'Stapler']);
    }

    public function test_invalid_inventory_type_is_listed_and_not_created(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity', 'Inventory type'],
            ['Stapler', '', 'piece', 5, '???'],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(0, $result['created']);
        $this->assertCount(1, $result['invalid']);
        $this->assertStringContainsString('Inventory type', $result['invalid'][0]['reason']);
        $this->assertDatabaseMissing(Item::class, ['name' => 'Stapler']);

        $schema = ItemImportAction::resultsSchema($result);
        $tabs = $schema[1]->getDefaultChildComponents();
        $invalidHtml = $tabs[3]->getDefaultChildComponents()[0]->getContent()->toHtml();
        $this->assertStringContainsString('Inventory type must include letters', $invalidHtml);
    }

    public function test_import_accepts_custom_inventory_type_and_reuses_it_as_a_suggestion(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity', 'Inventory type'],
            ['Screwdriver Set 9 Way', '', 'set', 0, 'Vehicle Maintenance Supply'],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(1, $result['created']);
        $this->assertSame([], $result['invalid']);
        $this->assertDatabaseHas(Item::class, [
            'name' => 'Screwdriver Set 9 Way',
            'inventory_type' => 'vehicle_maintenance_supply',
        ]);
        $this->assertSame(
            'Vehicle Maintenance Supply',
            ConsumableInventoryType::label('vehicle_maintenance_supply'),
        );
        $this->assertContains(
            'Vehicle Maintenance Supply',
            ConsumableInventoryType::suggestionLabels(),
        );
    }

    public function test_sample_download_action_returns_xlsx(): void
    {
        [, $category] = $this->consumableFixture();
        session(['active_item_category_id' => $category->id]);

        $response = ItemImportAction::sampleDownloadResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'consumable-items-import-sample.xlsx',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_import_action_opens_results_modal_after_import(): void
    {
        [$office, $category, $user] = $this->consumableFixture();
        $this->actingAs($user);
        session(['active_item_category_id' => $category->id]);

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity'],
            ['Alcohol', '500ml', 'bottle', 147],
            ['Ink', 'Black', '500ml', 3],
        ]);

        $upload = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'items.xlsx',
            (string) file_get_contents($path),
        );

        $component = Livewire::withQueryParams(['category' => (string) $category->id])
            ->test(ListItems::class)
            ->callAction(TestAction::make('importConsumableItems')->schemaComponent(true, 'content'), [
                'file' => $upload,
            ]);

        $component->assertActionMounted('importConsumableResults');
        $this->assertNotNull($component->instance()->importConsumableResult);
        $this->assertSame(1, (int) ($component->instance()->importConsumableResult['created'] ?? 0));
        $this->assertCount(1, $component->instance()->importConsumableResult['invalid'] ?? []);
        $this->assertDatabaseHas(Item::class, [
            'name' => 'Alcohol 500ml',
            'unit' => 'bottle',
            'sub_item' => '500ml',
        ]);

        $schema = ItemImportAction::resultsSchema($component->instance()->importConsumableResult);
        $this->assertGreaterThanOrEqual(2, count($schema));
        $this->assertInstanceOf(\Filament\Schemas\Components\Tabs::class, $schema[1]);

        /** @var \Filament\Schemas\Components\Tabs $tabsComponent */
        $tabsComponent = $schema[1];
        $tabs = $tabsComponent->getDefaultChildComponents();
        $this->assertIsArray($tabs);

        $tabLabels = array_map(
            fn ($tab): string => (string) $tab->getLabel(),
            $tabs,
        );
        $this->assertContains('Success', $tabLabels);
        $this->assertContains('Updated', $tabLabels);
        $this->assertContains('Skipped', $tabLabels);
        $this->assertContains('Invalid', $tabLabels);
        $this->assertCount(4, $tabs);
        $this->assertSame(4, $tabsComponent->getActiveTab());

        $summaryHtml = $schema[0]->getContent()->toHtml();
        $this->assertStringContainsString('1 created', $summaryHtml);
        $this->assertStringContainsString('0 updated', $summaryHtml);
        $this->assertStringContainsString('0 skipped', $summaryHtml);
        $this->assertStringContainsString('1 invalid', $summaryHtml);
        $this->assertStringContainsString('·', $summaryHtml);

        $successPlaceholders = $tabs[0]->getDefaultChildComponents();
        $this->assertNotEmpty($successPlaceholders);
        $successHtml = $successPlaceholders[0]->getContent()->toHtml();
        $this->assertStringContainsString('Excel', $successHtml);
        $this->assertStringContainsString('In system', $successHtml);
        $this->assertStringContainsString('500ml', $successHtml);
        $this->assertStringContainsString('Base', $successHtml);
        $this->assertStringContainsString('Sub-item', $successHtml);
        $this->assertStringContainsString('Created', $successHtml);

        $updatedPlaceholders = $tabs[1]->getDefaultChildComponents();
        $this->assertStringContainsString('No rows in this category.', $updatedPlaceholders[0]->getContent()->toHtml());

        $skippedPlaceholders = $tabs[2]->getDefaultChildComponents();
        $this->assertStringContainsString('No rows in this category.', $skippedPlaceholders[0]->getContent()->toHtml());

        $invalidPlaceholders = $tabs[3]->getDefaultChildComponents();
        $this->assertNotEmpty($invalidPlaceholders);
        $invalidHtml = $invalidPlaceholders[0]->getContent()->toHtml();
        $this->assertStringContainsString('Invalid rows', $invalidHtml);

        $component
            ->call('openConsumableImportResults')
            ->assertActionMounted('importConsumableResults');
    }

    public function test_import_results_skipped_tab_uses_continuous_numbering_across_subsections(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $withStock = Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
            'unit' => 'ream',
        ]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-IMPORT-NUM-1',
            'item_id' => $withStock->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'unit_cost' => 10,
            'acquisition_date' => now()->toDateString(),
            'recorded_by' => $user->id,
        ]);

        Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Stapler',
            'sub_item' => null,
            'name' => 'Stapler',
            'unit' => 'piece',
        ]);

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity'],
            ['Bond Paper', 'A4', 'ream', 99],
            ['Stapler', '', 'piece', 0],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $schema = ItemImportAction::resultsSchema($result);
        $tabs = $schema[1]->getDefaultChildComponents();
        $skippedHtml = $tabs[2]->getDefaultChildComponents()[0]->getContent()->toHtml();

        $this->assertStringContainsString('Showing 2 rows', $skippedHtml);
        $this->assertStringContainsString('1 of 2', $skippedHtml);
        $this->assertStringContainsString('>1</', $skippedHtml);
        $this->assertStringContainsString('>2</', $skippedHtml);

        $summaryHtml = $schema[0]->getContent()->toHtml();
        $this->assertMatchesRegularExpression('/created.*·.*updated.*·.*skipped.*·.*invalid/s', $summaryHtml);
    }

    public function test_import_results_preview_caps_large_tabs(): void
    {
        $limit = ItemImportAction::RESULTS_PREVIEW_LIMIT;
        $rows = [];
        for ($i = 1; $i <= $limit + 5; $i++) {
            $rows[] = [
                'status' => 'updated',
                'excel_row' => $i,
                'excel' => [
                    'base' => 'Item '.$i,
                    'sub' => null,
                    'unit' => 'piece',
                    'qty' => 0,
                    'item_name' => null,
                    'reorder_level' => 0,
                    'inventory_type' => 'vehicle_maintenance_supply',
                    'inventory_type_label' => 'Vehicle Maintenance Supply',
                    'days_to_consume' => null,
                    'description' => null,
                    'unit_cost' => null,
                ],
                'actual' => [
                    'base' => 'Item '.$i,
                    'sub' => null,
                    'unit' => 'piece',
                    'name' => 'Item '.$i,
                    'reorder_level' => 0,
                    'inventory_type' => 'vehicle_maintenance_supply',
                    'inventory_type_label' => 'Vehicle Maintenance Supply',
                    'days_to_consume' => null,
                    'description' => null,
                    'unit_cost' => null,
                ],
                'reason' => 'Filled blank catalog fields.',
            ];
        }

        $schema = ItemImportAction::resultsSchema([
            'created' => 0,
            'rows' => $rows,
        ]);
        $tabs = $schema[1]->getDefaultChildComponents();
        $updatedHtml = $tabs[1]->getDefaultChildComponents()[0]->getContent()->toHtml();

        $this->assertStringContainsString('Showing '.$limit.' of '.($limit + 5).' rows', $updatedHtml);
        $this->assertStringContainsString('Only the first '.$limit.' are listed here', $updatedHtml);
        $this->assertStringContainsString('>'.$limit.'</', $updatedHtml);
        $this->assertStringNotContainsString('>'.($limit + 1).'</', $updatedHtml);
    }

    public function test_import_action_registered_and_consumables_detection(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        $ppe = ItemCategory::factory()->create(['name' => 'PPE']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        session(['active_item_category_id' => $consumables->id]);
        $consumablesPage = Livewire::withQueryParams(['category' => (string) $consumables->id])
            ->test(ListItems::class);
        $consumablesPage->assertActionExists(TestAction::make('importConsumableItems')->schemaComponent(true, 'content'));
        $this->assertTrue($consumablesPage->instance()->isActiveConsumablesCategory());

        session(['active_item_category_id' => $ppe->id]);
        $ppePage = Livewire::withQueryParams(['category' => (string) $ppe->id])
            ->test(ListItems::class);
        $this->assertFalse($ppePage->instance()->isActiveConsumablesCategory());
        $ppePage->assertActionExists(TestAction::make('importConsumableItems')->schemaComponent(true, 'content'));
    }

    public function test_ppe_category_import_is_supported(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $ppe = ItemCategory::factory()->create(['name' => 'PPE']);
        $uacs = UacsObjectCode::query()->firstOrCreate(
            ['code' => '106-03'],
            [
                'name' => 'Office Equipment',
                'property_class' => ItemPropertyClass::OfficeEquipment,
                'is_active' => true,
            ],
        );
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $path = $this->writeSpreadsheet([
            ['Base item', 'Unit', 'Quantity', 'Type of PPE', 'UACS object code', 'Unit cost'],
            ['Desktop Computer', 'unit', 0, 'Office Equipment', '106-03', 75000],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $ppe->id,
            $office->id,
            $user,
        );

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas(Item::class, [
            'item_category_id' => $ppe->id,
            'ppe_type' => PpePropertyType::OfficeEquipment,
            'uacs_object_code_id' => $uacs->id,
        ]);
    }

    public function test_existing_item_with_no_qty_in_file_is_skipped_as_already_in_catalog(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Stapler',
            'sub_item' => null,
            'name' => 'Stapler',
            'unit' => 'piece',
        ]);

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity'],
            ['Stapler', '', 'piece', 0],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(['Stapler'], $result['skippedExistingNoQty']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, Item::query()->where('name', 'Stapler')->count());
    }

    public function test_import_maps_janitorial_supplies_inventory_type(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity', 'Inventory type'],
            ['Detergent', 'Powder', 'pack', 20, 'Janitorial supplies'],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas(Item::class, [
            'name' => 'Detergent Powder',
            'inventory_type' => ConsumableInventoryType::JanitorialSupplies,
        ]);
    }

    public function test_reimport_fills_blank_inventory_type_on_existing_item(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Stapler',
            'sub_item' => null,
            'name' => 'Stapler',
            'unit' => 'piece',
            'inventory_type' => null,
            'days_to_consume' => null,
            'description' => 'Already described',
            'reorder_level' => 3,
        ]);

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity', 'Inventory type'],
            ['Stapler', '', 'piece', 0, 'Office Supplies Inventory'],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(['Stapler'], $result['updatedNames']);
        $this->assertSame([], $result['skippedExistingNoQty']);
        $updatedRows = array_values(array_filter(
            $result['rows'],
            fn (array $row): bool => ($row['status'] ?? '') === 'updated',
        ));
        $this->assertCount(1, $updatedRows);
        $this->assertDatabaseHas(Item::class, [
            'name' => 'Stapler',
            'inventory_type' => ConsumableInventoryType::OfficeSupplies,
            'description' => 'Already described',
            'reorder_level' => 3,
        ]);

        $schema = ItemImportAction::resultsSchema($result);
        $tabs = $schema[1]->getDefaultChildComponents();
        $tabLabels = array_map(fn ($tab): string => (string) $tab->getLabel(), $tabs);
        $this->assertContains('Updated', $tabLabels);
        $updatedHtml = $tabs[1]->getDefaultChildComponents()[0]->getContent()->toHtml();
        $this->assertStringContainsString('Blank catalog fields filled', $updatedHtml);
        $this->assertStringContainsString('Office Supplies Inventory', $updatedHtml);
    }

    public function test_reimport_does_not_overwrite_inventory_type_already_set(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Stapler',
            'sub_item' => null,
            'name' => 'Stapler',
            'unit' => 'piece',
            'inventory_type' => ConsumableInventoryType::OfficeSupplies,
            'days_to_consume' => 10,
            'description' => 'Office stapler',
            'reorder_level' => 2,
        ]);

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity', 'Inventory type'],
            ['Stapler', '', 'piece', 0, 'Janitorial Supplies Inventory'],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame([], $result['updatedNames']);
        $this->assertSame(['Stapler'], $result['skippedExistingNoQty']);
        $this->assertDatabaseHas(Item::class, [
            'name' => 'Stapler',
            'inventory_type' => ConsumableInventoryType::OfficeSupplies,
        ]);
    }

    public function test_reimport_fills_blank_description_on_item_that_already_has_stock(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
            'unit' => 'ream',
            'inventory_type' => ConsumableInventoryType::OfficeSupplies,
            'days_to_consume' => 30,
            'description' => null,
            'reorder_level' => 5,
        ]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-IMPORT-DESC-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'unit_cost' => 10,
            'acquisition_date' => now()->toDateString(),
            'recorded_by' => $user->id,
        ]);

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity', 'Description'],
            ['Bond Paper', 'A4', 'ream', 99, 'Short bond paper'],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(['Bond Paper A4'], $result['updatedNames']);
        $this->assertSame([], $result['skippedHasStock']);
        $this->assertDatabaseHas(Item::class, [
            'name' => 'Bond Paper A4',
            'description' => 'Short bond paper',
            'inventory_type' => ConsumableInventoryType::OfficeSupplies,
        ]);
        $this->assertDatabaseMissing(StockOpeningBalance::class, [
            'item_id' => $item->id,
        ]);
        $this->assertSame(5, app(InventoryStockService::class)->getStock($item->id, $office->id));
    }

    public function test_reimport_does_not_overwrite_description_already_set(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Stapler',
            'sub_item' => null,
            'name' => 'Stapler',
            'unit' => 'piece',
            'inventory_type' => ConsumableInventoryType::OfficeSupplies,
            'days_to_consume' => 10,
            'description' => 'Keep this description',
            'reorder_level' => 2,
        ]);

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity', 'Description'],
            ['Stapler', '', 'piece', 0, 'Replacement description from file'],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame([], $result['updatedNames']);
        $this->assertSame(['Stapler'], $result['skippedExistingNoQty']);
        $this->assertDatabaseHas(Item::class, [
            'name' => 'Stapler',
            'description' => 'Keep this description',
        ]);
    }

    public function test_reimport_does_not_change_reorder_point_when_system_value_is_zero(): void
    {
        [$office, $category, $user] = $this->consumableFixture();

        Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Stapler',
            'sub_item' => null,
            'name' => 'Stapler',
            'unit' => 'piece',
            'inventory_type' => ConsumableInventoryType::OfficeSupplies,
            'days_to_consume' => 10,
            'description' => 'Office stapler',
            'reorder_level' => 0,
        ]);

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity', 'Reorder point'],
            ['Stapler', '', 'piece', 0, 15],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame([], $result['updatedNames']);
        $this->assertSame(['Stapler'], $result['skippedExistingNoQty']);
        $this->assertDatabaseHas(Item::class, [
            'name' => 'Stapler',
            'reorder_level' => 0,
        ]);
    }

    /**
     * @return array{0: Office, 1: ItemCategory, 2: User}
     */
    protected function consumableFixture(): array
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        return [$office, $category, $user];
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    protected function writeSpreadsheet(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);

        $path = tempnam(sys_get_temp_dir(), 'item_import_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->assertFileExists($path);

        return $path;
    }
}
