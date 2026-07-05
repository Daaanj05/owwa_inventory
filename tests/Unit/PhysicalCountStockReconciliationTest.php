<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\InventoryUnit;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Services\PhysicalCountPreloadService;
use App\Services\PhysicalCountStockReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhysicalCountStockReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_reports_matching_counts_when_units_and_ledger_align(): void
    {
        [$office, $category, $item, $user] = $this->createSemiFixtures();

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-REC-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 3,
            'unit_cost' => 500,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);

        $report = app(PhysicalCountStockReconciliationService::class)->reconcile($office->id, $category->id);

        $this->assertSame(3, $report['accountable_unit_count']);
        $this->assertSame(3, $report['warehouse_unit_count']);
        $this->assertSame(3, $report['ledger_stock_total']);
        $this->assertSame(0, $report['drift']);
        $this->assertSame([], $report['items']);
    }

    public function test_preload_line_count_matches_inventory_unit_count(): void
    {
        [$office, $category, $item, $user] = $this->createSemiFixtures();

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-REC-2',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'unit_cost' => 500,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);

        $session = PhysicalCountSession::query()->create([
            'reference_code' => 'PC-REC-0001',
            'count_type' => PhysicalCountSession::TYPE_RPCSP,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
        ]);

        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session);

        $report = app(PhysicalCountStockReconciliationService::class)->reconcile($office->id, $category->id);
        $lines = $session->fresh()->lines;

        $this->assertSame(1, $lines->count());
        $this->assertSame(2, (int) $lines->first()->balance_per_card);
        $this->assertSame($report['accountable_unit_count'], $lines->sum('balance_per_card'));
    }

    public function test_reconcile_reports_drift_when_extra_in_stock_units_exist(): void
    {
        [$office, $category, $item, $user] = $this->createSemiFixtures();

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-REC-3',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 500,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);

        InventoryUnit::query()->create([
            'property_number' => 'SPHV-2026-DRIFT-0001',
            'acquisition_id' => $acquisition->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'status' => InventoryUnit::STATUS_IN_STOCK,
            'article' => $item->name,
            'stock_number' => $item->item_code,
            'unit_of_measure' => $item->unit,
        ]);

        $report = app(PhysicalCountStockReconciliationService::class)->reconcile($office->id, $category->id);

        $this->assertSame(2, $report['warehouse_unit_count']);
        $this->assertSame(2, $report['accountable_unit_count']);
        $this->assertSame(1, $report['ledger_stock_total']);
        $this->assertSame(1, $report['drift']);
        $this->assertSame([], $report['items']);
    }

    public function test_reconcile_flags_missing_accountable_tags_per_item(): void
    {
        [$office, $category, $item, $user] = $this->createSemiFixtures();

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-REC-6',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'unit_cost' => 500,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);

        InventoryUnit::query()
            ->where('acquisition_id', $acquisition->id)
            ->orderBy('id')
            ->first()
            ?->delete();

        $report = app(PhysicalCountStockReconciliationService::class)->reconcile($office->id, $category->id);

        $this->assertCount(1, $report['items']);
        $this->assertSame(-1, $report['items'][0]['drift']);
    }

    public function test_preload_includes_issued_units_at_regional_office(): void
    {
        [$office, $category, $item, $user] = $this->createSemiFixtures();

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-REC-4',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'unit_cost' => 500,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);

        $units = InventoryUnit::query()->where('acquisition_id', $acquisition->id)->get();
        $units->first()?->update(['status' => InventoryUnit::STATUS_ISSUED]);

        $session = PhysicalCountSession::query()->create([
            'reference_code' => 'PC-REC-0002',
            'count_type' => PhysicalCountSession::TYPE_RPCSP,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
        ]);

        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session);

        $lines = $session->fresh()->lines;

        $this->assertSame(1, $lines->count());
        $this->assertSame(2, (int) $lines->first()->balance_per_card);
    }

    public function test_preload_excludes_units_at_satellite_office(): void
    {
        [$office, $category, $item, $user] = $this->createSemiFixtures();
        $satellite = Office::factory()->create(['is_satellite' => true, 'name' => 'Satellite A']);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-REC-5',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'unit_cost' => 500,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);

        InventoryUnit::query()
            ->where('acquisition_id', $acquisition->id)
            ->orderBy('id')
            ->first()
            ?->update(['office_id' => $satellite->id]);

        $session = PhysicalCountSession::query()->create([
            'reference_code' => 'PC-REC-0003',
            'count_type' => PhysicalCountSession::TYPE_RPCSP,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
        ]);

        app(PhysicalCountPreloadService::class)->preloadFromCustodyRecords($session);

        $this->assertSame(1, $session->fresh()->lines()->count());
    }

    /**
     * @return array{0: Office, 1: ItemCategory, 2: Item, 3: User}
     */
    protected function createSemiFixtures(): array
    {
        $office = Office::factory()->create(['fund_cluster' => '01']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $user = User::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'SEM-001',
            'unit' => 'unit',
        ]);

        return [$office, $category, $item, $user];
    }
}
