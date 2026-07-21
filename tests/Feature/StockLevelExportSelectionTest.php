<?php

namespace Tests\Feature;

use App\Filament\Pages\StockLevels;
use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $response->assertSee('None of the selected stock positions could be exported', false);
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
