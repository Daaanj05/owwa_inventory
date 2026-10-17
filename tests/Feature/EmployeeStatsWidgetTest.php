<?php

namespace Tests\Feature;

use App\Filament\Widgets\EmployeeStatsWidget;
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

class EmployeeStatsWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_employee_kpi_modals_list_matching_year_records(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Bond Paper A4',
        ]);

        /** @var User $employee */
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        /** @var User $custodian */
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'name' => 'Ana Custodian',
        ]);

        $acceptedRequisition = Requisition::query()->create([
            'transaction_number' => '2026-EMP-REQ-01',
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'purpose' => 'Office supplies',
            'created_at' => now()->startOfYear()->addDays(10),
        ]);

        Requisition::query()->create([
            'transaction_number' => '2026-EMP-PEND-01',
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
            'purpose' => 'Pending review request',
            'created_at' => now()->startOfYear()->addDays(20),
        ]);

        Issuance::query()->create([
            'requisition_id' => $acceptedRequisition->id,
            'office_id' => $office->id,
            'item_id' => $item->id,
            'issued_to' => $employee->id,
            'issued_by' => $custodian->id,
            'quantity' => 5,
            'issuance_date' => now()->startOfYear()->addMonths(2)->toDateString(),
        ]);

        $component = Livewire::actingAs($employee)
            ->test(EmployeeStatsWidget::class)
            ->assertSee('Requests sent')
            ->assertSee('Pending')
            ->assertSee('Items received')
            ->mountAction('viewRequestsSent')
            ->assertActionMounted('viewRequestsSent');

        $requestsHtml = (string) $component->instance()->getMountedAction()?->getModalContent();
        $this->assertStringContainsString('2026-EMP-REQ-01', $requestsHtml);
        $this->assertStringContainsString('2026-EMP-PEND-01', $requestsHtml);
        $this->assertStringContainsString('Office supplies', $requestsHtml);

        $component->unmountAction()
            ->mountAction('viewPendingRequests')
            ->assertActionMounted('viewPendingRequests');

        $pendingHtml = (string) $component->instance()->getMountedAction()?->getModalContent();
        $this->assertStringContainsString('2026-EMP-PEND-01', $pendingHtml);
        $this->assertStringNotContainsString('2026-EMP-REQ-01', $pendingHtml);

        $component->unmountAction()
            ->mountAction('viewItemsReceived')
            ->assertActionMounted('viewItemsReceived');

        $receivedHtml = (string) $component->instance()->getMountedAction()?->getModalContent();
        $this->assertStringContainsString('Bond Paper A4', $receivedHtml);
        $this->assertStringContainsString('Consumables', $receivedHtml);
        $this->assertStringContainsString('Ana Custodian', $receivedHtml);
    }

    public function test_employee_is_denied_requisition_ris_export_route(): void
    {
        $office = Office::factory()->create();

        /** @var User $employee */
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'transaction_number' => '2026-01-0499',
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $this->actingAs($employee)
            ->get(route('owwa.export.requisition', $requisition))
            ->assertForbidden();
    }

    public function test_only_supply_custodian_can_export_requisition_ris(): void
    {
        $office = Office::factory()->create();

        /** @var User $uc */
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0498',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $this->actingAs($uc)
            ->get(route('owwa.export.requisition', $requisition))
            ->assertForbidden();

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($custodian)
            ->get(route('owwa.export.requisition', $requisition))
            ->assertOk();
    }
}
