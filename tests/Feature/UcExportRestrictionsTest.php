<?php

namespace Tests\Feature;

use App\Filament\Resources\Distributions\Actions\DistributionViewActions;
use App\Filament\Resources\Requisitions\Actions\RequisitionExportActions;
use App\Models\Department;
use App\Models\Distribution;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UcExportRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_consolidator_cannot_export_ris(): void
    {
        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);

        $this->assertFalse(RequisitionExportActions::userCanExportRis($uc));
    }

    public function test_supply_custodian_can_export_ris(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $this->assertTrue(RequisitionExportActions::userCanExportRis($custodian));
    }

    public function test_list_requisitions_bulk_export_ris_visible_only_for_supply_custodian(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Requisitions/Pages/ListRequisitions.php'));

        $this->assertStringContainsString('RequisitionExportActions::userCanExportRis', $source);
    }

    public function test_distribution_export_owwa_action_visible_only_for_supply_custodian(): void
    {
        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->seedDistributionRecord();

        $this->actingAs($uc);
        $this->assertFalse(DistributionViewActions::exportOwwaAction()->isVisible());

        $this->actingAs($custodian);
        $this->assertTrue(DistributionViewActions::exportOwwaAction()->isVisible());
    }

    public function test_distributions_table_source_restricts_standalone_export_owwa_to_supply_custodian(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Distributions/Tables/DistributionsTable.php'));

        $this->assertStringContainsString('isSupplyCustodian()', $source);
    }

    protected function seedDistributionRecord(): Distribution
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        return Distribution::query()->create([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'quantity' => 2,
            'distribution_date' => now(),
            'distributed_by' => $user->id,
            'distributed_to' => $employee->id,
        ]);
    }
}
