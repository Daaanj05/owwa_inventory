<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Services\DisposalInventoryUnitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisposalInventoryUnitServiceTest extends TestCase
{
    use RefreshDatabase;

    private DisposalInventoryUnitService $service;

    /** @var array<string, mixed> */
    private array $formState = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DisposalInventoryUnitService::class);
        $this->formState = [];
    }

    public function test_sync_clears_fields_when_item_or_office_missing(): void
    {
        $this->formState = [
            'inventory_unit_id' => 99,
            'property_number' => 'SE-001',
            'acquisition_cost' => 1000,
            'par_issuance_id' => 5,
            'inventory_auto_synced' => true,
        ];

        $this->service->syncFormStateForItemOffice(null, 1, $this->formSetter());

        $this->assertNull($this->formState['inventory_unit_id']);
        $this->assertNull($this->formState['property_number']);
        $this->assertNull($this->formState['acquisition_cost']);
        $this->assertNull($this->formState['par_issuance_id']);
        $this->assertFalse($this->formState['inventory_auto_synced']);
    }

    public function test_sync_auto_selects_single_available_unit(): void
    {
        ['item' => $item, 'office' => $office, 'unit' => $unit, 'issuance' => $issuance] = $this->seedSemiUnits(1);

        $this->service->syncFormStateForItemOffice($item->id, $office->id, $this->formSetter());

        $this->assertSame($unit->id, $this->formState['inventory_unit_id']);
        $this->assertSame($unit->property_number, $this->formState['property_number']);
        $this->assertSame(4500.0, $this->formState['acquisition_cost']);
        $this->assertSame($issuance->id, $this->formState['par_issuance_id']);
        $this->assertSame(1, $this->formState['quantity']);
        $this->assertTrue($this->formState['inventory_auto_synced']);
    }

    public function test_sync_prefills_cost_only_when_multiple_units_exist(): void
    {
        ['item' => $item, 'office' => $office] = $this->seedSemiUnits(3);

        $this->service->syncFormStateForItemOffice($item->id, $office->id, $this->formSetter());

        $this->assertNull($this->formState['inventory_unit_id']);
        $this->assertNull($this->formState['property_number']);
        $this->assertNull($this->formState['par_issuance_id']);
        $this->assertSame(4500.0, $this->formState['acquisition_cost']);
        $this->assertTrue($this->formState['inventory_auto_synced']);
    }

    public function test_sync_prefills_cost_from_latest_acquisition_when_no_units(): void
    {
        $office = Office::factory()->create(['code' => '01']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-NO-UNITS',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 3200,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        InventoryUnit::query()
            ->where('item_id', $item->id)
            ->where('office_id', $office->id)
            ->delete();

        $this->service->syncFormStateForItemOffice($item->id, $office->id, $this->formSetter());

        $this->assertNull($this->formState['inventory_unit_id']);
        $this->assertNull($this->formState['property_number']);
        $this->assertSame(3200.0, $this->formState['acquisition_cost']);
        $this->assertTrue($this->formState['inventory_auto_synced']);
    }

    public function test_resolve_latest_acquisition_cost_prefers_office_scoped_acquisition(): void
    {
        $officeA = Office::factory()->create(['code' => '01']);
        $officeB = Office::factory()->create(['code' => '02']);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-A',
            'item_id' => $item->id,
            'office_id' => $officeA->id,
            'quantity' => 1,
            'unit_cost' => 1000,
            'acquisition_date' => now()->subDay(),
            'recorded_by' => $custodian->id,
        ]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-B',
            'item_id' => $item->id,
            'office_id' => $officeB->id,
            'quantity' => 1,
            'unit_cost' => 2500,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $this->assertSame(2500.0, $this->service->resolveLatestAcquisitionCost($item->id, $officeB->id));
        $this->assertSame(1000.0, $this->service->resolveLatestAcquisitionCost($item->id, $officeA->id));
    }

    /**
     * @return array{item: Item, office: Office, unit: InventoryUnit, issuance: Issuance}
     */
    private function seedSemiUnits(int $quantity): array
    {
        $office = Office::factory()->create(['code' => '01']);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-SYNC-'.$quantity,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => $quantity,
            'unit_cost' => 4500,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);
        $unit = InventoryUnit::query()->where('acquisition_id', $acquisition->id)->orderBy('id')->first();
        $this->assertNotNull($unit);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-'.str_pad((string) $quantity, 4, '0', STR_PAD_LEFT),
            'office_id' => $office->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => '2026-01-'.str_pad((string) ($quantity + 100), 4, '0', STR_PAD_LEFT),
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_cost' => 4500,
            'issuance_date' => now(),
            'issued_by' => $custodian->id,
            'property_number' => $unit->property_number,
        ]);

        $unit->update([
            'status' => InventoryUnit::STATUS_ISSUED,
            'issuance_id' => $issuance->id,
        ]);

        return [
            'item' => $item,
            'office' => $office,
            'unit' => $unit->fresh(['acquisition', 'issuance']),
            'issuance' => $issuance,
        ];
    }

    private function formSetter(): callable
    {
        return function (string $path, mixed $value): void {
            $this->formState[$path] = $value;
        };
    }
}
