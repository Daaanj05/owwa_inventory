<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_cannot_export_another_office_acquisition(): void
    {
        [$acquisition] = $this->createAcquisitionFixture();

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $acquisition->office_id,
        ]);

        $this->actingAs($employee)
            ->get(route('owwa.export.acquisition', $acquisition))
            ->assertForbidden();
    }

    public function test_custodian_cannot_export_acquisition_from_other_office(): void
    {
        [$acquisition, $office] = $this->createAcquisitionFixture();
        $otherOffice = Office::factory()->create();

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $otherOffice->id,
        ]);

        $this->assertNotSame($office->id, $otherOffice->id);

        $this->actingAs($custodian)
            ->get(route('owwa.export.acquisition', $acquisition))
            ->assertForbidden();
    }

    public function test_custodian_can_export_acquisition_for_own_office(): void
    {
        [$acquisition, $office, $category] = $this->createAcquisitionFixture();

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        session(['active_item_category_id' => $category->id]);

        $this->actingAs($custodian)
            ->get(route('owwa.export.acquisition', $acquisition))
            ->assertOk();
    }

    public function test_employee_cannot_download_coa_stock_level_report(): void
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $this->actingAs($employee)
            ->get(route('reports.coa.stock-level'))
            ->assertForbidden();
    }

    /**
     * @return array{0: Acquisition, 1: Office, 2: ItemCategory}
     */
    protected function createAcquisitionFixture(): array
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
        ]);
        $recorder = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $acquisition = Acquisition::query()->create([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 100,
            'acquisition_date' => now(),
            'recorded_by' => $recorder->id,
            'reference_code' => 'ACQ-AUTH-0001',
        ]);

        return [$acquisition, $office, $category];
    }
}
