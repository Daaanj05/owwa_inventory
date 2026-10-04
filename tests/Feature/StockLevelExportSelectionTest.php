<?php

namespace Tests\Feature;

use App\Filament\Pages\StockLevels;
use App\Jobs\GenerateStockCardExportJob;
use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\StockLevelExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class StockLevelExportSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_stock_cards_export_url_includes_selected_pairs(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'CON-300',
        ]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-'.$item->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 3,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->set('selectedKeys', [$item->id.':'.$office->id.':0'])
            ->assertSet('selectedKeys', [$item->id.':'.$office->id.':0']);

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->set('selectedKeys', [$item->id.':'.$office->id.':0']);

        $url = $component->instance()->buildStockCardsExportUrl('selected', 'xlsx');

        $this->assertStringContainsString('pairs='.$item->id.'%3A'.$office->id.'%3A0', $url);
        $this->assertStringContainsString('category='.$category->id, $url);
    }

    public function test_export_modal_uses_all_stocks_label(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->mountAction('exportStockCardsFastExcel')
            ->assertActionMounted('exportStockCardsFastExcel')
            ->assertFormFieldExists('export_scope')
            ->assertSchemaStateSet([
                'export_scope' => 'all',
            ]);

        $action = $component->instance()->exportStockCardsFastExcelAction();
        $schemaProperty = new \ReflectionProperty($action, 'schema');
        $formComponents = $schemaProperty->getValue($action);
        $this->assertIsArray($formComponents);

        $exportScope = collect($formComponents)->first(
            fn (mixed $field): bool => is_object($field)
                && method_exists($field, 'getName')
                && $field->getName() === 'export_scope'
        );

        $this->assertNotNull($exportScope);
        $options = $exportScope->getOptions();
        $this->assertSame('All stocks', $options['all'] ?? null);
        $this->assertNotContains('All filtered rows', $options);
    }

    public function test_export_pair_count_for_all_scope_uses_export_service(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        foreach (range(1, 3) as $i) {
            $item = Item::factory()->create([
                'item_category_id' => $category->id,
                'item_code' => 'CON-C'.$i,
            ]);
            Acquisition::query()->create([
                'reference_code' => 'ACQ-C'.$item->id,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 1,
                'acquisition_date' => now(),
                'recorded_by' => $custodian->id,
            ]);
        }

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id]);

        $this->assertSame(3, $component->instance()->exportPairCountForScope('all'));
        $this->assertSame(0, $component->instance()->exportPairCountForScope('selected'));
    }

    public function test_one_file_export_url_includes_elevated_export_max(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id]);

        $url = $component->instance()->buildStockCardsExportUrl(
            scope: 'all',
            format: 'pdf',
            exportMax: StockLevelExportService::SINGLE_MAX,
        );

        $this->assertStringContainsString('export_max='.StockLevelExportService::SINGLE_MAX, $url);
        $this->assertStringContainsString('format=pdf', $url);
    }

    public function test_batch_export_urls_chunk_pair_keys(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $keys = [];
        for ($i = 1; $i <= 250; $i++) {
            $keys[] = $i.':'.$office->id;
        }

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id]);

        $urls = $component->instance()->buildStockCardsBatchExportUrls($keys, 'xlsx');

        $this->assertCount(3, $urls);
        $this->assertStringContainsString('pairs=', $urls[0]);
        $this->assertStringNotContainsString('export_max=', $urls[0]);
    }

    public function test_stock_cards_export_rejects_over_batch_without_export_max(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $keys = [];
        for ($i = 1; $i <= StockLevelExportService::BATCH_SIZE + 1; $i++) {
            $item = Item::factory()->create([
                'item_category_id' => $category->id,
                'item_code' => 'CON-B'.$i,
            ]);
            Acquisition::query()->create([
                'reference_code' => 'ACQ-B'.$item->id,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 1,
                'acquisition_date' => now(),
                'recorded_by' => $custodian->id,
            ]);
            $keys[] = $item->id.':'.$office->id;
        }

        $response = $this->actingAs($custodian)->get(route('owwa.export.bulk.stock-cards', [
            'category' => $category->id,
            'pairs' => implode(',', $keys),
        ]));

        $response->assertStatus(422);
    }

    public function test_over_single_max_batch_urls_chunk_by_batch_size(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $keys = [];
        for ($i = 1; $i <= StockLevelExportService::SINGLE_MAX + 1; $i++) {
            $keys[] = $i.':'.$office->id;
        }

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id]);

        $urls = $component->instance()->buildStockCardsBatchExportUrls($keys, 'xlsx');

        $this->assertCount(6, $urls);

        foreach ($urls as $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $pairCount = substr_count((string) ($query['pairs'] ?? ''), ',') + 1;
            $this->assertLessThanOrEqual(StockLevelExportService::BATCH_SIZE, $pairCount);
            $this->assertArrayNotHasKey('export_max', $query);
        }
    }

    public function test_over_batch_size_queues_export_instead_of_sync_download(): void
    {
        Queue::fake();

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $keys = [];
        for ($i = 1; $i <= StockLevelExportService::BATCH_SIZE + 5; $i++) {
            $keys[] = $i.':'.$office->id.':0';
        }

        $this->actingAs($custodian);

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->set('selectedKeys', $keys);

        $result = $component->instance()->runStockCardsExport('xlsx', 'selected', 'batches');

        $this->assertSame('queued', $result);
        $component->assertSet('exportBusy', false);

        Queue::assertPushed(GenerateStockCardExportJob::class, function (GenerateStockCardExportJob $job) use ($custodian, $keys): bool {
            return $job->userId === (int) $custodian->id
                && $job->format === 'xlsx'
                && $job->downloadSize === 'batches'
                && $job->scope === 'selected'
                && count($job->selectedKeys) === count($keys);
        });
    }

    public function test_export_pair_keys_memoizes_all_scope_collect(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'CON-MEMO',
        ]);
        Acquisition::query()->create([
            'reference_code' => 'ACQ-MEMO'.$item->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id]);

        $first = $component->instance()->exportPairKeysForScope('all');
        $second = $component->instance()->exportPairKeysForScope('all');

        $this->assertSame($first, $second);
        $this->assertCount(1, $first);
    }

    public function test_under_batch_size_does_not_queue_export(): void
    {
        Queue::fake();

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'CON-SYNC',
        ]);
        Acquisition::query()->create([
            'reference_code' => 'ACQ-SYNC'.$item->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $this->actingAs($custodian);

        $component = Livewire::actingAs($custodian)
            ->test(StockLevels::class, ['category' => $category->id])
            ->set('selectedKeys', [$item->id.':'.$office->id.':0']);

        $result = $component->instance()->runStockCardsExport('xlsx', 'selected', 'one');

        $this->assertSame('sync', $result);
        $component->assertSet('exportBusy', true);

        Queue::assertNotPushed(GenerateStockCardExportJob::class);
    }

    public function test_stock_cards_export_allows_elevated_export_max(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Consumable/Stock Levels & Recording/Appendix 58 - SC.xlsx'))) {
            $this->markTestSkipped('Appendix 58 SC template is not present in storage/app/templates.');
        }

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $keys = [];
        for ($i = 1; $i <= StockLevelExportService::BATCH_SIZE + 1; $i++) {
            $item = Item::factory()->create([
                'item_category_id' => $category->id,
                'item_code' => 'CON-E'.$i,
            ]);
            Acquisition::query()->create([
                'reference_code' => 'ACQ-E'.$item->id,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 1,
                'acquisition_date' => now(),
                'recorded_by' => $custodian->id,
            ]);
            $keys[] = $item->id.':'.$office->id;
        }

        $response = $this->actingAs($custodian)->get(route('owwa.export.bulk.stock-cards', [
            'category' => $category->id,
            'pairs' => implode(',', $keys),
            'export_max' => StockLevelExportService::SINGLE_MAX,
        ]));

        $response->assertOk();
    }

    public function test_selected_pair_export_downloads_workbook(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Consumable/Stock Levels & Recording/Appendix 58 - SC.xlsx'))) {
            $this->markTestSkipped('Appendix 58 SC template is not present in storage/app/templates.');
        }

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'CON-400',
        ]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-'.$item->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $response = $this->actingAs($custodian)->get(route('owwa.export.bulk.stock-cards', [
            'category' => $category->id,
            'pairs' => $item->id.':'.$office->id.':0',
        ]));

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
    }

    public function test_invalid_selected_pairs_return_validation_error(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $response = $this->actingAs($custodian)->get(route('owwa.export.bulk.stock-cards', [
            'category' => $category->id,
            'pairs' => '999999:'.$office->id.':0',
        ]));

        $response->assertStatus(422);
    }

    public function test_selected_ppe_pair_export_downloads_workbook(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/ppe/Accquisition/Appendix 69 - PC.xls'))) {
            $this->markTestSkipped('Appendix 69 PC template is not present in storage/app/templates.');
        }

        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'PPE-400',
        ]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-'.$item->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $response = $this->actingAs($custodian)->get(route('owwa.export.bulk.stock-cards', [
            'category' => $category->id,
            'pairs' => $item->id.':'.$office->id.':0',
        ]));

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
    }

    public function test_selected_semi_pair_export_downloads_workbook(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is required to read OWWA .xlsx templates.');
        }

        if (! is_readable(storage_path('app/templates/Semi-Expendable/Recording (Stock Levels)/Property-Form-Annex-A.1-Semi-expendable-Property-Card.xlsx'))) {
            $this->markTestSkipped('Annex A.1 template is not present in storage/app/templates.');
        }

        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'SEM-400',
            'property_class' => \App\Support\ItemPropertyClass::OfficeEquipment,
        ]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-'.$item->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $response = $this->actingAs($custodian)->get(route('owwa.export.bulk.stock-cards', [
            'category' => $category->id,
            'pairs' => $item->id.':'.$office->id.':0',
        ]));

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
    }
}
