<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemStockBucket;
use App\Models\Office;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Models\PropertyNumberBucket;
use App\Models\Requisition;
use App\Models\UacsObjectCode;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Services\CatalogAssetNumberService;
use App\Services\PhysicalCountPreloadService;
use App\Services\SemiExpendablePropertyNumberBuilder;
use App\Support\ItemPropertyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemiExpendablePropertyNumberPerItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_acquisition_assigns_the_next_sequence_to_each_unit(): void
    {
        $office = Office::factory()->create(['code' => 'OWWA-IVA']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'property_class' => ItemPropertyClass::OfficeEquipment,
        ]);
        $user = User::factory()->create();

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-MULTI-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'unit_cost' => 4500,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition->fresh(['item.category', 'office']));

        $units = InventoryUnit::query()->where('acquisition_id', $acquisition->id)->get();
        $bucket = ItemStockBucket::findForItemCost((int) $item->id, 4500.0);

        $this->assertCount(5, $units);
        $this->assertSame(5, $units->pluck('property_number')->unique()->count());
        $this->assertNotNull($bucket?->property_number);
        $this->assertSame($units->first()->property_number, $bucket->property_number);
        $this->assertSame($units->first()->property_number, $item->fresh()->semi_expendable_property_number);
    }

    public function test_quantity_two_uses_the_next_two_yearly_sequences(): void
    {
        $office = Office::factory()->create([
            'code' => 'OWWA-IVA',
            'is_regional_supply' => true,
        ]);
        $uacs = UacsObjectCode::query()->create([
            'code' => '106',
            'name' => 'Placeholder',
            'is_active' => true,
        ]);
        PropertyNumberBucket::query()->create([
            'bucket_key' => CatalogAssetNumberService::SERIES_SEMI.'|'.now()->format('Y'),
            'next_sequence' => 83,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'property_class' => ItemPropertyClass::OfficeEquipment,
            'uacs_object_code_id' => $uacs->id,
        ]);
        $user = User::factory()->create();

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-SEQ-2',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'unit_cost' => 8000,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition->fresh(['item.category', 'office']));

        $numbers = InventoryUnit::query()
            ->where('acquisition_id', $acquisition->id)
            ->orderBy('id')
            ->pluck('property_number')
            ->all();
        $year = now()->format('Y');

        $this->assertSame([
            "SPHV-{$year}-OE-106-083-OWWA-IVA",
            "SPHV-{$year}-OE-106-084-OWWA-IVA",
        ], $numbers);
        $this->assertSame("SPHV-{$year}-OE-106-083-OWWA-IVA", $item->fresh()->semi_expendable_property_number);
    }

    public function test_different_unit_costs_share_one_property_number(): void
    {
        $office = Office::factory()->create(['code' => 'OWWA-IVA']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'property_class' => ItemPropertyClass::OfficeEquipment,
        ]);
        $user = User::factory()->create();

        $lowCost = Acquisition::query()->create([
            'reference_code' => 'ACQ-LOW',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 4500,
            'acquisition_date' => now()->subYear(),
            'recorded_by' => $user->id,
        ]);

        $highCost = Acquisition::query()->create([
            'reference_code' => 'ACQ-HIGH',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 12000,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($lowCost->fresh(['item.category', 'office']));
        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($highCost->fresh(['item.category', 'office']));

        $lowBucket = ItemStockBucket::findForItemCost((int) $item->id, 4500.0);
        $highBucket = ItemStockBucket::findForItemCost((int) $item->id, 12000.0);

        $lowUnit = InventoryUnit::query()->where('acquisition_id', $lowCost->id)->first();
        $highUnit = InventoryUnit::query()->where('acquisition_id', $highCost->id)->first();

        $this->assertNotNull($lowUnit?->property_number);
        $this->assertNotNull($highUnit?->property_number);
        $this->assertNotSame($lowUnit->property_number, $highUnit->property_number);
        $this->assertSame($lowUnit->property_number, $item->fresh()->semi_expendable_property_number);
        $this->assertSame($lowUnit->property_number, $lowBucket?->property_number);
        $this->assertSame($item->fresh()->semi_expendable_property_number, $highBucket?->property_number);
    }

    public function test_second_issuance_for_same_cost_reuses_acquisition_property_number(): void
    {
        $office = Office::factory()->create(['code' => 'OWWA-IVA']);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'property_class' => ItemPropertyClass::Ict,
        ]);
        $custodian = User::factory()->create();
        $user = User::factory()->create();

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-REUSE-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'unit_cost' => 4500,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition->fresh(['item.category', 'office']));

        $canonicalNumber = $item->fresh()->semi_expendable_property_number;
        $this->assertNotNull($canonicalNumber);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0100',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $custodian->id,
            'status' => 'pending',
        ]);

        $first = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_cost' => 4500,
            'issuance_date' => now(),
            'issued_by' => $custodian->id,
            'issued_to' => $custodian->id,
            'property_number' => $canonicalNumber,
        ]);

        $second = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_cost' => 4500,
            'issuance_date' => now(),
            'issued_by' => $custodian->id,
            'issued_to' => $custodian->id,
            'property_number' => $canonicalNumber,
        ]);

        $this->assertSame($canonicalNumber, app(SemiExpendablePropertyNumberBuilder::class)->resolveExistingForIssuance($first));
        $this->assertSame($canonicalNumber, app(SemiExpendablePropertyNumberBuilder::class)->resolveExistingForIssuance($second));
        $this->assertSame(
            $canonicalNumber,
            ItemStockBucket::findForItemCost((int) $item->id, 4500.0)?->property_number,
        );
    }

    public function test_rpcsp_preload_aggregates_units_by_property_number(): void
    {
        $office = Office::factory()->create(['code' => 'OWWA-IVA']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'property_class' => ItemPropertyClass::OfficeEquipment,
        ]);
        $user = User::factory()->create();

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-RPCSP-AGG',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 4,
            'unit_cost' => 4500,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition->fresh(['item.category', 'office']));

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCSP,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
        ]);

        app(PhysicalCountPreloadService::class)->preloadFromInventoryUnits($session);

        $lines = PhysicalCountLine::query()->where('physical_count_session_id', $session->id)->get();

        $this->assertCount(4, $lines);
        $this->assertTrue($lines->every(fn (PhysicalCountLine $line): bool => (int) $line->balance_per_card === 1));
    }
}
