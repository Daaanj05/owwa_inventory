<?php

namespace Tests\Feature;

use App\Filament\Widgets\LowStockWidget;
use App\Filament\Widgets\UnitConsolidatorStatsWidget;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UnitConsolidatorStatsWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_unit_consolidator_does_not_see_low_stock_widget(): void
    {
        $office = Office::factory()->create();

        /** @var User $uc */
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $this->actingAs($uc);

        $this->assertFalse(LowStockWidget::canView());
        $this->assertTrue(UnitConsolidatorStatsWidget::canView());
    }

    public function test_unit_consolidator_kpi_modals_list_matching_records_and_page_links(): void
    {
        $office = Office::factory()->create();
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);
        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);

        $bondPaper = Item::factory()->create([
            'item_category_id' => $consumables->id,
            'name' => 'Bond Paper A4',
        ]);
        $chair = Item::factory()->create([
            'item_category_id' => $semi->id,
            'name' => 'Office Chair',
        ]);

        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Administrative Division',
            'code' => 'ADM',
        ]);

        /** @var User $uc */
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'name' => 'Unit Consolidator',
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);

        /** @var User $employee */
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'name' => 'Juan Employee',
        ]);

        Requisition::query()->create([
            'transaction_number' => '2026-EMP-PEND-UC',
            'office_id' => $office->id,
            'department_id' => $uc->department_id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
            'purpose' => 'Need bond paper',
            'created_at' => now()->startOfYear()->addDays(5),
        ]);

        $requisitionForIssuance = Requisition::query()->create([
            'reference_code' => '2026-01-EUL-UC',
            'office_id' => $office->id,
            'department_id' => $uc->department_id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        Issuance::query()->create([
            'requisition_id' => $requisitionForIssuance->id,
            'reference_code' => 'ICS-EUL-1',
            'office_id' => $office->id,
            'department_id' => $uc->department_id,
            'item_id' => $chair->id,
            'issued_to' => $employee->id,
            'issued_by' => $uc->id,
            'quantity' => 1,
            'unit_cost' => 1000,
            'issuance_date' => now()->subDays((int) round(5 * 365.25) - 20)->toDateString(),
            'estimated_useful_life' => '5 years',
            'property_number' => 'SEMI-001',
        ]);

        Issuance::query()->create([
            'requisition_id' => $requisitionForIssuance->id,
            'consolidated_requisition_id' => $requisitionForIssuance->id,
            'reference_code' => 'RIS-BOND-1',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'item_id' => $bondPaper->id,
            'issued_to' => $employee->id,
            'issued_by' => $uc->id,
            'quantity' => 8,
            'unit_cost' => 185,
            'issuance_date' => now()->startOfYear()->addMonths(1)->toDateString(),
        ]);

        $this->actingAs($uc);

        $component = Livewire::test(UnitConsolidatorStatsWidget::class)
            ->assertSee('Pending employee requests')
            ->assertSee('Property nearing EUL')
            ->assertSee('Items issued')
            ->assertDontSee('Low stock')
            ->assertDontSee('Issued this month')
            ->mountAction('viewPendingEmployeeRequests')
            ->assertActionMounted('viewPendingEmployeeRequests');

        $pendingHtml = (string) $component->instance()->getMountedAction()?->getModalContent();
        $this->assertStringContainsString('2026-EMP-PEND-UC', $pendingHtml);
        $this->assertStringContainsString('Juan Employee', $pendingHtml);
        $this->assertStringContainsString('Need bond paper', $pendingHtml);

        $component->unmountAction()
            ->mountAction('viewPropertyNearingEul')
            ->assertActionMounted('viewPropertyNearingEul');

        $eulHtml = (string) $component->instance()->getMountedAction()?->getModalContent();
        $this->assertStringContainsString('Office Chair', $eulHtml);
        $this->assertStringContainsString('SEMI-001', $eulHtml);

        $component->unmountAction()
            ->mountAction('viewItemsDistributed')
            ->assertActionMounted('viewItemsDistributed');

        $distributedHtml = (string) $component->instance()->getMountedAction()?->getModalContent();
        $this->assertStringContainsString('Bond Paper A4', $distributedHtml);
        $this->assertStringContainsString('Juan Employee', $distributedHtml);
    }

    public function test_pending_employee_requests_include_every_assigned_department(): void
    {
        $office = Office::factory()->create();
        $administrative = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Administrative Division',
            'code' => 'ADM',
        ]);
        $finance = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Finance Division',
            'code' => 'FIN',
        ]);

        /** @var User $uc */
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $administrative->id,
            'name' => 'Danjediael Pelicano Caranay',
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $administrative->id],
            ['office_id' => $office->id, 'department_id' => $finance->id],
        ]);

        /** @var User $employee */
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $finance->id,
            'name' => 'Jennilyn Buenavente Pataueg',
        ]);

        Requisition::query()->create([
            'transaction_number' => '2026-FIN-PEND',
            'office_id' => $office->id,
            'department_id' => $finance->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
            'purpose' => 'For the October liquidation and payroll document set.',
            'created_at' => now(),
        ]);

        $this->actingAs($uc);

        $component = Livewire::test(UnitConsolidatorStatsWidget::class)
            ->assertSee('Pending employee requests')
            ->mountAction('viewPendingEmployeeRequests');

        $pendingHtml = (string) $component->instance()->getMountedAction()?->getModalContent();
        $this->assertStringContainsString('2026-FIN-PEND', $pendingHtml);
        $this->assertStringContainsString('Jennilyn Buenavente Pataueg', $pendingHtml);
    }
}
