<?php

namespace Tests\Feature;

use App\Filament\Resources\Items\Actions\ItemImportAction;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\UacsObjectCode;
use App\Models\User;
use App\Services\ImportConsumableItemsService;
use App\Support\ConsumableItemSpreadsheetReader;
use App\Support\ItemPropertyClass;
use App\Support\PpePropertyType;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ItemImportSemiPpeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_semi_import_creates_item_with_property_class_uacs_and_eul(): void
    {
        [$office, $category, $user, $uacs] = $this->semiFixture();

        $path = $this->writeSpreadsheet([
            ['Base item', 'Sub-item', 'Unit', 'Quantity', 'Property class', 'UACS object code', 'Estimated useful life', 'Unit cost'],
            ['Office Chair', 'Ergonomic', 'piece', 2, 'Office Equipment', '106-03', 36, 15000],
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
            'name' => 'Office Chair Ergonomic',
            'property_class' => ItemPropertyClass::OfficeEquipment,
            'uacs_object_code_id' => $uacs->id,
            'estimated_useful_life' => '36',
        ]);
        $this->assertSame(2, InventoryUnit::query()->count());
    }

    public function test_ppe_import_creates_item_with_type_uacs_and_starting_stock(): void
    {
        [$office, $category, $user, $uacs] = $this->ppeFixture();

        $path = $this->writeSpreadsheet([
            ['Base item', 'Unit', 'Quantity', 'Type of PPE', 'UACS object code', 'Unit cost'],
            ['Desktop Computer', 'unit', 1, 'Office Equipment', '106-03', 75000],
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
            'name' => 'Desktop Computer',
            'ppe_type' => PpePropertyType::OfficeEquipment,
            'uacs_object_code_id' => $uacs->id,
        ]);
        $this->assertSame(1, InventoryUnit::query()->count());
    }

    public function test_sample_spreadsheets_include_category_specific_headers(): void
    {
        $semiSheet = ConsumableItemSpreadsheetReader::sampleSemiExpendableSpreadsheet()->getActiveSheet();
        $semiRows = $semiSheet->toArray(null, true, true, false);
        $semiHeaders = $semiRows[0];

        $ppeSheet = ConsumableItemSpreadsheetReader::samplePpeSpreadsheet()->getActiveSheet();
        $ppeRows = $ppeSheet->toArray(null, true, true, false);
        $ppeHeaders = $ppeRows[0];

        $this->assertContains('Property class', $semiHeaders);
        $this->assertContains('Estimated useful life', $semiHeaders);
        $this->assertContains('Type of PPE', $ppeHeaders);
        $this->assertNotContains('Inventory type', $ppeHeaders);

        $this->assertTrue($semiSheet->getStyle('A1')->getFont()->getBold());
        $this->assertTrue($ppeSheet->getStyle('A1')->getFont()->getBold());

        $this->assertCount(count(ItemPropertyClass::options()) + 1, $semiRows);
        $this->assertCount(count(PpePropertyType::options()) + 1, $ppeRows);
    }

    public function test_import_action_visible_on_semi_and_ppe_lists(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $ppe = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'PPE'],
        );
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        session(['active_item_category_id' => $semi->id]);
        Livewire::withQueryParams(['category' => (string) $semi->id])
            ->test(ListItems::class)
            ->assertActionExists(TestAction::make('importConsumableItems')->schemaComponent(true, 'content'));

        session(['active_item_category_id' => $ppe->id]);
        Livewire::withQueryParams(['category' => (string) $ppe->id])
            ->test(ListItems::class)
            ->assertActionExists(TestAction::make('importConsumableItems')->schemaComponent(true, 'content'));
    }

    public function test_wrong_category_consumable_sheet_on_ppe_page_fails_before_create(): void
    {
        [, $category] = $this->ppeFixture();

        $path = $this->writeSpreadsheet([
            ['Base item', 'Unit', 'Quantity', 'Inventory type'],
            ['Bond Paper', 'ream', 5, 'Office Supplies'],
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(ImportConsumableItemsService::class)->importFromPath($path, $category->id);
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Consumables', collect($exception->errors())->flatten()->first() ?? '');
            $this->assertSame(0, Item::query()->count());

            throw $exception;
        }
    }

    public function test_mixed_template_headers_fail_whole_import(): void
    {
        [, $category] = $this->semiFixture();

        $path = $this->writeSpreadsheet([
            ['Base item', 'Unit', 'Quantity', 'Inventory type', 'Property class'],
            ['Chair', 'piece', 1, 'Office Supplies', 'Office Equipment'],
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(ImportConsumableItemsService::class)->importFromPath($path, $category->id);
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('mixes columns', collect($exception->errors())->flatten()->first() ?? '');
            $this->assertSame(0, Item::query()->count());

            throw $exception;
        }
    }

    public function test_headerless_base_unit_qty_on_semi_still_imports_without_distinctive_headers(): void
    {
        [$office, $category, $user] = $this->semiFixture();

        $path = $this->writeSpreadsheet([
            ['Office Chair', 'piece', 0],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertSame(0, $result['created']);
        $this->assertCount(1, $result['invalid']);
        $this->assertStringContainsString('Property class is required', $result['invalid'][0]['reason']);
    }

    public function test_semi_import_rejects_unknown_property_class_and_missing_uacs(): void
    {
        [$office, $category, $user] = $this->semiFixture();

        $unknownClassPath = $this->writeSpreadsheet([
            ['Base item', 'Unit', 'Quantity', 'Property class', 'UACS object code', 'Estimated useful life'],
            ['Chair', 'piece', 0, 'Mystery Class', '106-03', 36],
        ]);

        $unknownResult = app(ImportConsumableItemsService::class)->importFromPath(
            $unknownClassPath,
            $category->id,
            $office->id,
            $user,
        );
        $this->assertStringContainsString('Property class', $unknownResult['invalid'][0]['reason']);

        $missingUacsPath = $this->writeSpreadsheet([
            ['Base item', 'Unit', 'Quantity', 'Property class', 'UACS object code', 'Estimated useful life'],
            ['Table', 'piece', 0, 'Office Equipment', '999-99', 36],
        ]);

        $missingUacsResult = app(ImportConsumableItemsService::class)->importFromPath(
            $missingUacsPath,
            $category->id,
            $office->id,
            $user,
        );
        $this->assertStringContainsString('UACS object code', $missingUacsResult['invalid'][0]['reason']);
    }

    public function test_semi_and_ppe_starting_stock_cost_thresholds_are_enforced(): void
    {
        [$office, $category, $user] = $this->semiFixture();

        $semiTooHighPath = $this->writeSpreadsheet([
            ['Base item', 'Unit', 'Quantity', 'Property class', 'UACS object code', 'Estimated useful life', 'Unit cost'],
            ['Server', 'unit', 1, 'Office Equipment', '106-03', 36, 75000],
        ]);

        $semiResult = app(ImportConsumableItemsService::class)->importFromPath(
            $semiTooHighPath,
            $category->id,
            $office->id,
            $user,
        );
        $this->assertStringContainsString('less than', $semiResult['invalid'][0]['reason']);

        [$office, $ppeCategory, $user] = $this->ppeFixture();

        $ppeTooLowPath = $this->writeSpreadsheet([
            ['Base item', 'Unit', 'Quantity', 'Type of PPE', 'UACS object code', 'Unit cost'],
            ['Laptop', 'unit', 1, 'Office Equipment', '106-03', 10000],
        ]);

        $ppeResult = app(ImportConsumableItemsService::class)->importFromPath(
            $ppeTooLowPath,
            $ppeCategory->id,
            $office->id,
            $user,
        );
        $this->assertStringContainsString('at least', $ppeResult['invalid'][0]['reason']);
    }

    public function test_reimport_fills_blank_semi_and_ppe_fields_only(): void
    {
        [$office, $category, $user, $uacs] = $this->semiFixture();

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Office Chair',
            'sub_item' => null,
            'name' => 'Office Chair',
            'unit' => 'piece',
            'property_class' => null,
            'uacs_object_code_id' => null,
            'estimated_useful_life' => null,
            'description' => 'Existing description',
        ]);

        $path = $this->writeSpreadsheet([
            ['Base item', 'Unit', 'Quantity', 'Property class', 'UACS object code', 'Estimated useful life', 'Description'],
            ['Office Chair', 'piece', 0, 'Office Equipment', '106-03', 36, 'Should not overwrite'],
        ]);

        $result = app(ImportConsumableItemsService::class)->importFromPath(
            $path,
            $category->id,
            $office->id,
            $user,
        );

        $this->assertCount(1, $result['updatedNames']);
        $item->refresh();
        $this->assertSame(ItemPropertyClass::OfficeEquipment, $item->property_class);
        $this->assertSame($uacs->id, $item->uacs_object_code_id);
        $this->assertSame('36', $item->estimated_useful_life);
        $this->assertSame('Existing description', $item->description);

        [$office, $ppeCategory, $user, $uacs] = $this->ppeFixture();
        $ppeItem = Item::factory()->create([
            'item_category_id' => $ppeCategory->id,
            'base_name' => 'Desktop Computer',
            'name' => 'Desktop Computer',
            'unit' => 'unit',
            'ppe_type' => null,
            'uacs_object_code_id' => null,
        ]);

        $ppePath = $this->writeSpreadsheet([
            ['Base item', 'Unit', 'Quantity', 'Type of PPE', 'UACS object code'],
            ['Desktop Computer', 'unit', 0, 'Office Equipment', '106-03'],
        ]);

        app(ImportConsumableItemsService::class)->importFromPath(
            $ppePath,
            $ppeCategory->id,
            $office->id,
            $user,
        );

        $ppeItem->refresh();
        $this->assertSame(PpePropertyType::OfficeEquipment, $ppeItem->ppe_type);
        $this->assertSame($uacs->id, $ppeItem->uacs_object_code_id);
    }

    public function test_property_class_and_ppe_type_resolve_official_labels_and_keys(): void
    {
        $this->assertSame(
            ItemPropertyClass::OfficeEquipment,
            ItemPropertyClass::resolve('Office Equipment'),
        );
        $this->assertSame(
            ItemPropertyClass::OfficeEquipment,
            ItemPropertyClass::resolve('office_equipment'),
        );
        $this->assertNull(ItemPropertyClass::resolve('Not A Real Class'));

        $this->assertSame(
            PpePropertyType::OfficeEquipment,
            PpePropertyType::resolve('Office Equipment'),
        );
        $this->assertNull(PpePropertyType::resolve('Imaginary PPE'));
    }

    public function test_sample_download_uses_category_filename(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        session(['active_item_category_id' => $category->id]);

        $response = ItemImportAction::sampleDownloadResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'semi-expendable-items-import-sample.xlsx',
            (string) $response->headers->get('content-disposition'),
        );
    }

    /**
     * @return array{0: Office, 1: ItemCategory, 2: User, 3: UacsObjectCode}
     */
    protected function semiFixture(): array
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
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

        return [$office, $category, $user, $uacs];
    }

    /**
     * @return array{0: Office, 1: ItemCategory, 2: User, 3: UacsObjectCode}
     */
    protected function ppeFixture(): array
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'PPE'],
        );
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

        return [$office, $category, $user, $uacs];
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    protected function writeSpreadsheet(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);

        $path = tempnam(sys_get_temp_dir(), 'item_import_semi_ppe_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
