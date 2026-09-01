<?php

namespace Tests\Unit;

use App\Models\Distribution;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Support\IssuanceDistributionVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssuanceDistributionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_distribution_when_no_employee_handoffs(): void
    {
        [$issuance] = $this->seedIssuanceContext();

        $summary = IssuanceDistributionVisibility::forIssuance($issuance);

        $this->assertSame(IssuanceDistributionVisibility::STATUS_PENDING, $summary['distribution_status']);
        $this->assertSame('Pending distribution', $summary['distribution_status_label']);
        $this->assertSame('Unit Consolidator', $summary['unit_consolidator']);
        $this->assertSame([], $summary['employees']);
        $this->assertSame('Unit Consolidator', IssuanceDistributionVisibility::holderLabelForIssuance($issuance));
    }

    public function test_direct_employee_issuance_shows_employee_holder_with_uc(): void
    {
        $office = Office::factory()->create();
        $item = Item::factory()->create();
        $uc = User::factory()->create([
            'name' => 'Unit Consolidator',
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $employee = User::factory()->create([
            'name' => 'Employee Holder',
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $consolidated = Requisition::query()->create([
            'reference_code' => 'REQ-DIRECT-1',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $employeeRequisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => 'EMP-1',
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $employeeRequisition->id,
            'consolidated_requisition_id' => $consolidated->id,
            'reference_code' => 'ICS-DIRECT-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'issuance_date' => now()->toDateString(),
            'issued_to' => $employee->id,
        ]);

        $summary = IssuanceDistributionVisibility::forIssuance($issuance->fresh());

        $this->assertSame(IssuanceDistributionVisibility::STATUS_ISSUED, $summary['distribution_status']);
        $this->assertSame('Issued to employee', $summary['distribution_status_label']);
        $this->assertSame($uc->name, $summary['unit_consolidator']);
        $this->assertCount(1, $summary['employees']);
        $this->assertSame($employee->name, $summary['employees'][0]['name']);
        $this->assertSame(
            "{$employee->name} (via {$uc->name})",
            IssuanceDistributionVisibility::holderLabelForIssuance($issuance->fresh()),
        );
    }

    public function test_distributed_status_and_employee_holder_label_for_legacy_flow(): void
    {
        [$issuance, $uc, $employee] = $this->seedIssuanceContext();

        Distribution::query()->create([
            'office_id' => $issuance->office_id,
            'requisition_id' => $issuance->requisition_id,
            'item_id' => $issuance->item_id,
            'quantity' => $issuance->quantity,
            'distributed_by' => $uc->id,
            'distributed_to' => $employee->id,
            'distribution_date' => now()->toDateString(),
        ]);

        $summary = IssuanceDistributionVisibility::forIssuance($issuance->fresh());

        $this->assertSame(IssuanceDistributionVisibility::STATUS_DISTRIBUTED, $summary['distribution_status']);
        $this->assertSame('Distributed', $summary['distribution_status_label']);
        $this->assertCount(1, $summary['employees']);
        $this->assertSame($employee->name, $summary['employees'][0]['name']);
        $this->assertSame(
            "{$employee->name} (via {$uc->name})",
            IssuanceDistributionVisibility::holderLabelForIssuance($issuance->fresh()),
        );
    }

    public function test_unit_consolidator_label_ignores_supply_custodian_issued_to(): void
    {
        [$issuance, $uc] = $this->seedIssuanceContext();
        $custodian = User::factory()->create([
            'name' => 'Supply Custodian',
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $issuance->office_id,
        ]);

        $issuance->update(['issued_to' => $custodian->id]);

        $summary = IssuanceDistributionVisibility::forIssuance($issuance->fresh(['issuedTo', 'requisition.requestedBy']));

        $this->assertSame($uc->name, $summary['unit_consolidator']);
        $this->assertNotSame('Supply Custodian', $summary['unit_consolidator']);
    }

    /**
     * @return array{0: Issuance, 1: User, 2: User}
     */
    private function seedIssuanceContext(): array
    {
        $office = Office::factory()->create();
        $item = Item::factory()->create();
        $uc = User::factory()->create([
            'name' => 'Unit Consolidator',
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $employee = User::factory()->create([
            'name' => 'Employee Holder',
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => 'REQ-DIST-1',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => 'ICS-DIST-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'issuance_date' => now()->toDateString(),
            'issued_to' => $uc->id,
        ]);

        return [$issuance, $uc, $employee];
    }
}
