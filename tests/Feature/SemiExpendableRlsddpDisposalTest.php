<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Disposal;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Services\DisposalInventoryUnitService;
use App\Services\OwwaTemplateExportService;
use App\Support\DisposalExportLayout;
use App\Support\OwwaCellMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemiExpendableRlsddpDisposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_disposal_marks_inventory_unit_disposed_on_create(): void
    {
        ['unit' => $unit, 'office' => $office, 'item' => $item, 'custodian' => $custodian] = $this->seedSemiUnit();

        Disposal::query()->create([
            'reference_code' => '2026-01-0701',
            'item_id' => $item->id,
            'inventory_unit_id' => $unit->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'disposal_date' => now(),
            'disposal_type' => 'lost_stolen_damaged',
            'property_number' => $unit->property_number,
            'acquisition_cost' => 4500,
            'property_status' => 'damaged',
            'circumstances' => 'Broken during transport.',
            'custodian_printed_name' => 'Accountable Officer',
            'recorded_by' => $custodian->id,
        ]);

        $this->assertSame(InventoryUnit::STATUS_DISPOSED, $unit->fresh()->status);
    }

    public function test_rlsddp_export_uses_inventory_unit_property_number_and_cost(): void
    {
        ['unit' => $unit, 'office' => $office, 'item' => $item, 'issuance' => $issuance, 'acquisition' => $acquisition] = $this->seedSemiUnit();

        $disposal = new Disposal([
            'reference_code' => '2026-01-0702',
            'quantity' => 1,
            'disposal_date' => now(),
            'disposal_type' => 'lost_stolen_damaged',
            'property_status' => 'damaged',
            'circumstances' => 'Screen cracked.',
            'custodian_printed_name' => 'Accountable Officer',
            'immediate_supervisor_printed_name' => 'Supervisor',
        ]);
        $disposal->setRelation('office', $office);
        $disposal->setRelation('item', $item);
        $disposal->setRelation('parIssuance', $issuance);
        $disposal->setRelation('inventoryUnit', $unit);
        $unit->setRelation('acquisition', $acquisition);

        $values = DisposalExportLayout::cellValuesForRlsddp($disposal);
        $detailStart = OwwaCellMapping::detailRowBase('RLSDDP');
        $cols = OwwaCellMapping::detailColumns('RLSDDP');

        $this->assertSame(
            $unit->property_number,
            $values[OwwaCellMapping::columnCell($cols['property_no'], $detailStart)],
        );
        $this->assertSame(
            4500.0,
            $values[OwwaCellMapping::columnCell($cols['acquisition_cost'], $detailStart)],
        );
    }

    public function test_unit_service_resolves_cost_from_linked_acquisition(): void
    {
        ['unit' => $unit, 'acquisition' => $acquisition] = $this->seedSemiUnit();

        $cost = app(DisposalInventoryUnitService::class)->resolveAcquisitionCostForUnit($unit->fresh(['acquisition']));

        $this->assertSame(4500.0, $cost);
        $this->assertSame((float) $acquisition->unit_cost, $cost);
    }

    public function test_rlsddp_export_maps_department_office_from_stored_department(): void
    {
        ['unit' => $unit, 'office' => $office, 'item' => $item, 'issuance' => $issuance, 'acquisition' => $acquisition] = $this->seedSemiUnit();
        $department = $issuance->department;
        $this->assertNotNull($department);

        $office->update(['name' => 'OWWA Regional Office']);

        $disposal = new Disposal([
            'reference_code' => '2026-01-0704',
            'quantity' => 1,
            'disposal_date' => now(),
            'disposal_type' => 'lost_stolen_damaged',
            'property_status' => 'damaged',
            'circumstances' => 'Screen cracked.',
            'custodian_printed_name' => 'Accountable Officer',
            'department_id' => $department->id,
        ]);
        $disposal->setRelation('office', $office->fresh());
        $disposal->setRelation('department', $department);
        $disposal->setRelation('item', $item);
        $disposal->setRelation('parIssuance', $issuance);
        $disposal->setRelation('inventoryUnit', $unit);
        $unit->setRelation('acquisition', $acquisition);

        $values = DisposalExportLayout::cellValuesForRlsddp($disposal);
        $header = OwwaCellMapping::form('RLSDDP')['header'];

        $this->assertSame(
            ($header['entity_name']['label'] ?? '').'OWWA Regional Office',
            $values[$header['entity_name']['cell']],
        );
        $this->assertSame(
            ($header['department_office']['label'] ?? '').'Admin',
            $values[$header['department_office']['cell']],
        );
    }

    public function test_rlsddp_export_falls_back_to_par_department_then_office_for_department_office(): void
    {
        ['unit' => $unit, 'office' => $office, 'item' => $item, 'issuance' => $issuance, 'acquisition' => $acquisition] = $this->seedSemiUnit();
        $department = $issuance->department;
        $this->assertNotNull($department);

        $office->update(['name' => 'OWWA Regional Office']);

        $disposal = new Disposal([
            'reference_code' => '2026-01-0705',
            'quantity' => 1,
            'disposal_date' => now(),
            'disposal_type' => 'lost_stolen_damaged',
            'property_status' => 'lost',
            'circumstances' => 'Missing.',
            'custodian_printed_name' => 'Accountable Officer',
        ]);
        $disposal->setRelation('office', $office->fresh());
        $disposal->setRelation('item', $item);
        $disposal->setRelation('parIssuance', $issuance->load('department'));
        $disposal->setRelation('inventoryUnit', $unit);
        $unit->setRelation('acquisition', $acquisition);

        $values = DisposalExportLayout::cellValuesForRlsddp($disposal);
        $header = OwwaCellMapping::form('RLSDDP')['header'];

        $this->assertSame(
            ($header['department_office']['label'] ?? '').'Admin',
            $values[$header['department_office']['cell']],
        );

        $disposalWithoutDepartment = new Disposal([
            'reference_code' => '2026-01-0706',
            'quantity' => 1,
            'disposal_date' => now(),
            'disposal_type' => 'lost_stolen_damaged',
            'property_status' => 'lost',
            'circumstances' => 'Missing.',
            'custodian_printed_name' => 'Accountable Officer',
        ]);
        $disposalWithoutDepartment->setRelation('office', $office->fresh());
        $disposalWithoutDepartment->setRelation('item', $item);
        $disposalWithoutDepartment->setRelation('parIssuance', null);
        $disposalWithoutDepartment->setRelation('inventoryUnit', $unit);

        $fallbackValues = DisposalExportLayout::cellValuesForRlsddp($disposalWithoutDepartment);

        $this->assertSame(
            ($header['department_office']['label'] ?? '').'OWWA Regional Office',
            $fallbackValues[$header['department_office']['cell']],
        );
    }

    public function test_unit_service_sets_department_from_issuance_when_applying_unit(): void
    {
        ['unit' => $unit, 'issuance' => $issuance] = $this->seedSemiUnit();
        $unit->load('issuance');
        $state = [];

        app(DisposalInventoryUnitService::class)->applyUnitToFormState($unit, function (string $key, mixed $value) use (&$state): void {
            $state[$key] = $value;
        });

        $this->assertSame($issuance->id, $state['par_issuance_id'] ?? null);
        $this->assertSame($issuance->department_id, $state['department_id'] ?? null);
    }

    public function test_template_export_service_routes_semi_lost_disposal_to_rlsddp(): void
    {
        ['unit' => $unit, 'office' => $office, 'item' => $item] = $this->seedSemiUnit();

        $disposal = Disposal::query()->create([
            'reference_code' => '2026-01-0703',
            'item_id' => $item->id,
            'inventory_unit_id' => $unit->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'disposal_date' => now(),
            'disposal_type' => 'lost_stolen_damaged',
            'property_number' => $unit->property_number,
            'acquisition_cost' => 4500,
            'property_status' => 'lost',
            'circumstances' => 'Missing after office move.',
            'custodian_printed_name' => 'Officer',
            'recorded_by' => User::factory()->create()->id,
        ]);

        $values = app(OwwaTemplateExportService::class)->cellValuesForDisposal(
            $disposal->fresh(['item.category', 'office', 'inventoryUnit', 'parIssuance']),
            'Incident report/Appendix 75 - RLSDDP.xls',
        );

        $detailStart = OwwaCellMapping::detailRowBase('RLSDDP');
        $cols = OwwaCellMapping::detailColumns('RLSDDP');

        $this->assertSame(
            $unit->property_number,
            $values[OwwaCellMapping::columnCell($cols['property_no'], $detailStart)],
        );
    }

    /**
     * @return array{
     *     unit: InventoryUnit,
     *     office: Office,
     *     item: Item,
     *     custodian: User,
     *     acquisition: Acquisition,
     *     issuance: Issuance
     * }
     */
    private function seedSemiUnit(): array
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
            'reference_code' => 'ACQ-RLSDDP-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 3,
            'unit_cost' => 4500,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);
        $unit = InventoryUnit::query()->where('acquisition_id', $acquisition->id)->orderBy('id')->first();
        $this->assertNotNull($unit);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0799',
            'office_id' => $office->id,
            'requested_by' => $custodian->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => '2026-01-0801',
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
            'unit' => $unit->fresh(['acquisition', 'issuance']),
            'office' => $office,
            'item' => $item,
            'custodian' => $custodian,
            'acquisition' => $acquisition,
            'issuance' => $issuance,
        ];
    }
}
