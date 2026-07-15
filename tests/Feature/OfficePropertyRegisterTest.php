<?php

namespace Tests\Feature;

use App\Filament\Pages\OfficePropertyRegister;
use App\Models\Department;
use App\Models\Distribution;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\OfficePropertyRegisterService;
use App\Support\SemiExpendableUsefulLife;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class OfficePropertyRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_unit_consolidator_sees_office_property_registry_stock_cards(): void
    {
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
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Bond Paper',
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
            'quantity' => 10,
            'unit_cost' => 450,
            'issuance_date' => Carbon::parse('2026-01-01'),
            'issued_by' => $uc->id,
            'issued_to' => $uc->id,
        ]);

        Distribution::query()->create([
            'item_id' => $item->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'quantity' => 3,
            'distribution_date' => Carbon::parse('2026-01-15'),
            'distributed_by' => $uc->id,
            'distributed_to' => $employee->id,
        ]);

        $this->actingAs($uc)
            ->get(OfficePropertyRegister::getUrl(['category' => $category->id]))
            ->assertOk()
            ->assertSee('Office Property Registry')
            ->assertSee('Bond Paper')
            ->assertSee('Received')
            ->assertSee('Distributed')
            ->assertSee('Balance')
            ->assertSee('10')
            ->assertSee('3')
            ->assertSee('7');
    }

    public function test_uc_can_open_office_stock_ledger_modal(): void
    {
        [$uc, $item] = $this->seedSemiExpendableIssuance();

        Livewire::actingAs($uc)
            ->test(OfficePropertyRegister::class, ['category' => $item->item_category_id])
            ->call('openOfficeStockLedger', $item->id)
            ->assertActionMounted('viewOfficeStockLedger');
    }

    public function test_register_shows_nearing_eul_badge_in_ledger_modal(): void
    {
        Carbon::setTestNow('2026-06-01');
        config(['inventory.eul_nearing_days' => 90]);

        [$uc, $item] = $this->seedSemiExpendableIssuance(
            issuanceDate: '2021-07-01',
            eulExpiresAt: '2026-08-01',
        );

        $ledger = app(OfficePropertyRegisterService::class)->presentOfficeStockLedger($uc, $item->id);

        $this->assertTrue($ledger['show_property_units']);
        $this->assertNotEmpty($ledger['property_units']);
        $this->assertSame(SemiExpendableUsefulLife::STATUS_NEARING, $ledger['property_units'][0]['eul_status']);

        Livewire::actingAs($uc)
            ->test(OfficePropertyRegister::class, ['category' => $item->item_category_id])
            ->call('openOfficeStockLedger', $item->id)
            ->assertActionMounted('viewOfficeStockLedger');

        Carbon::setTestNow();
    }

    public function test_supply_custodian_cannot_access_register(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $this->actingAs($custodian)
            ->get(OfficePropertyRegister::getUrl())
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Item}
     */
    protected function seedSemiExpendableIssuance(
        string $issuanceDate = '2024-01-01',
        ?string $eulExpiresAt = null,
    ): array {
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
            'reference_code' => '2026-01-0302',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => '2026-01-0303',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_cost' => 4500,
            'issuance_date' => Carbon::parse($issuanceDate),
            'issued_by' => $uc->id,
            'issued_to' => $uc->id,
            'property_number' => 'SPLV-2024-ICT-01-01-001',
            'estimated_useful_life' => '5 yrs',
            'eul_expires_at' => $eulExpiresAt ? Carbon::parse($eulExpiresAt) : null,
        ]);

        return [$uc, $item];
    }
}
