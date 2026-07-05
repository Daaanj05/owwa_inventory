<?php

namespace Tests\Feature;

use App\Models\AcquisitionPaperwork;
use App\Models\Disposal;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemStockBucket;
use App\Models\PhysicalCountSession;
use App\Models\PhysicalInventoryPlan;
use App\Services\OwwaItemReportService;
use App\Support\ItemPropertyClass;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetInventoryDemoCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_inventory_command_truncates_and_reseeds_export_demo(): void
    {
        $this->seed(DatabaseSeeder::class);

        $staleItem = Item::factory()->create(['item_code' => 'STALE-001']);

        $this->artisan('demo:reset-inventory', ['--force' => true])
            ->assertSuccessful();

        $this->assertFalse(Item::query()->where('item_code', 'STALE-001')->exists());
        $this->assertTrue(Item::query()->where('item_code', 'CON-001')->exists());
        $this->assertTrue(Item::query()->where('item_code', 'CON-EXPORT-001')->exists());

        $this->assertGreaterThanOrEqual(3, AcquisitionPaperwork::query()->count());
        $this->assertTrue(AcquisitionPaperwork::query()->where('reference_code', 'DEMO-PR-CON-PSDBM-Q1')->exists());
        $this->assertGreaterThanOrEqual(
            15,
            \App\Models\Acquisition::query()->whereNotNull('acquisition_paperwork_id')->count(),
        );

        $consumables = ItemCategory::query()->where('name', 'Consumables')->first();
        $this->assertNotNull($consumables);
        $this->assertGreaterThanOrEqual(
            4,
            AcquisitionPaperwork::query()->where('item_category_id', $consumables->id)->count(),
        );

        $this->assertNotNull(PhysicalCountSession::query()->where('reference_code', 'PC-DEMO-RPCI-2026')->first());
        $this->assertNotNull(PhysicalCountSession::query()->where('reference_code', 'PC-DEMO-RPCSP-2026')->first());
        $this->assertNotNull(PhysicalCountSession::query()->where('reference_code', 'PC-DEMO-RPCPPE-2026')->first());

        $this->assertSame(0, Issuance::query()->whereNull('requisition_id')->count());

        $semiCategory = ItemCategory::query()->where('name', 'Semi-Expendable')->first();
        $this->assertNotNull($semiCategory);
        $this->assertSame(
            0,
            app(OwwaItemReportService::class)->countStockLevelItemsMissingPropertyClass($semiCategory->id, null),
        );

        foreach ([
            ItemPropertyClass::Ict,
            ItemPropertyClass::OfficeEquipment,
            ItemPropertyClass::FurnituresFixtures,
            ItemPropertyClass::SportsEquipment,
            ItemPropertyClass::MedicalEquipment,
            ItemPropertyClass::VehicleEquipment,
        ] as $propertyClass) {
            $this->assertTrue(
                Item::query()
                    ->where('item_category_id', $semiCategory->id)
                    ->where('property_class', $propertyClass)
                    ->exists(),
                "Missing semi demo item for property class {$propertyClass}",
            );
        }

        $rlsddp = Disposal::query()->where('disposal_type', 'lost_stolen_damaged')->first();
        $this->assertNotNull($rlsddp);
        $this->assertNotNull($rlsddp->inventory_unit_id);

        $this->assertNotNull(PhysicalInventoryPlan::query()->where('reference_code', 'IP-DEMO-FY2026')->first());
        $this->assertGreaterThanOrEqual(2, Disposal::query()->where('disposal_type', 'lost_stolen_damaged')->count());

        $rpci = PhysicalCountSession::query()->where('reference_code', 'PC-EXPORT-RPCI-2026')->first();
        $rpcsp = PhysicalCountSession::query()->where('reference_code', 'PC-EXPORT-RPCSP-2026')->first();
        $rpcppe = PhysicalCountSession::query()->where('reference_code', 'PC-EXPORT-RPCPPE-2026')->first();

        $this->assertNotNull($rpci);
        $this->assertNotNull($rpcsp);
        $this->assertNotNull($rpcppe);
        $this->assertGreaterThanOrEqual(70, $rpci->lines()->count());
        $this->assertGreaterThanOrEqual(70, $rpcsp->lines()->count());
        $this->assertGreaterThanOrEqual(70, $rpcppe->lines()->count());

        $sampleItem = Item::query()->where('item_code', 'SEM-EXPORT-001')->first();
        $this->assertNotNull($sampleItem);
        $acquisitionCost = $sampleItem->acquisitions()->orderBy('id')->value('unit_cost');
        $this->assertNotNull(ItemStockBucket::findForItemCost((int) $sampleItem->id, $acquisitionCost !== null ? (float) $acquisitionCost : null));

        $stapler = Item::query()->where('item_code', 'SEM-001')->first();
        $this->assertNotNull($stapler);
        $this->assertGreaterThanOrEqual(2, $stapler->acquisitions()->count());
        $this->assertNotNull($stapler->property_class);
        $this->assertNotNull($stapler->estimated_useful_life);
    }

    public function test_dry_run_does_not_truncate_items(): void
    {
        $this->seed(DatabaseSeeder::class);

        $item = Item::factory()->create(['item_code' => 'KEEP-ME']);

        $this->artisan('demo:reset-inventory', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertNotNull(Item::query()->find($item->id));
    }
}
