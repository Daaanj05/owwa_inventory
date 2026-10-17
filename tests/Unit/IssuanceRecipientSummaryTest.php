<?php

namespace Tests\Unit;

use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Support\IssuanceRecipientSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssuanceRecipientSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuance_to_unit_consolidator_has_no_employee_recipient(): void
    {
        [$issuance] = $this->seedIssuanceContext();

        $summary = IssuanceRecipientSummary::forIssuance($issuance);

        $this->assertSame('Unit Consolidator', $summary['unit_consolidator']);
        $this->assertNull($summary['employee']);
        $this->assertSame('Unit Consolidator', IssuanceRecipientSummary::holderLabelForIssuance($issuance));
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

        $summary = IssuanceRecipientSummary::forIssuance($issuance->fresh());

        $this->assertSame($uc->name, $summary['unit_consolidator']);
        $this->assertSame($employee->name, $summary['employee']);
        $this->assertSame(
            "{$employee->name} (via {$uc->name})",
            IssuanceRecipientSummary::holderLabelForIssuance($issuance->fresh()),
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

        $summary = IssuanceRecipientSummary::forIssuance($issuance->fresh(['issuedTo', 'requisition.requestedBy']));

        $this->assertSame($uc->name, $summary['unit_consolidator']);
        $this->assertNull($summary['employee']);
    }

    /**
     * @return array{0: Issuance, 1: User}
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

        $requisition = Requisition::query()->create([
            'reference_code' => 'REQ-UC-1',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'requisition_id' => $requisition->id,
            'reference_code' => 'ICS-UC-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'issuance_date' => now()->toDateString(),
            'issued_to' => $uc->id,
        ]);

        return [$issuance, $uc];
    }
}
