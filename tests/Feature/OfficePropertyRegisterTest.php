<?php

namespace Tests\Feature;

use App\Filament\Pages\OfficePropertyRegister;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class OfficePropertyRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_consolidator_can_view_office_property_register(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'estimated_useful_life' => '5 yrs',
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0300',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => '2026-01-0301',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_cost' => 4500,
            'issuance_date' => Carbon::parse('2024-01-01'),
            'issued_by' => $uc->id,
            'issued_to' => $uc->id,
            'property_number' => 'SPLV-2024-ICT-01-01-001',
            'estimated_useful_life' => '5 yrs',
        ]);

        $this->actingAs($uc)
            ->get(OfficePropertyRegister::getUrl())
            ->assertOk()
            ->assertSee('Office property register')
            ->assertSee('SPLV-2024-ICT-01-01-001')
            ->assertSee('5 yrs');
    }

    public function test_register_shows_nearing_eul_badge(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-06-01');
        config(['inventory.eul_nearing_days' => 90]);

        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => '01',
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0302',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $expiresAt = Carbon::parse('2026-08-01');

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => '2026-01-0303',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_cost' => 4500,
            'issuance_date' => Carbon::parse('2021-07-01'),
            'issued_by' => $uc->id,
            'issued_to' => $uc->id,
            'property_number' => 'SPLV-2021-ICT-01-01-002',
            'estimated_useful_life' => '5 yrs',
            'eul_expires_at' => $expiresAt,
        ]);

        Livewire::actingAs($uc)
            ->test(OfficePropertyRegister::class)
            ->assertSee('Nearing');

        Carbon::setTestNow();
    }

    public function test_supply_custodian_cannot_access_register(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $this->actingAs($custodian)
            ->get(OfficePropertyRegister::getUrl())
            ->assertForbidden();
    }
}
