<?php

namespace Tests\Unit;

use App\Models\Issuance;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Support\OwwaTransactionViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssuanceRisNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_ris_number_uses_consolidated_reference_not_employee_transaction(): void
    {
        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'first_name' => 'Jennilyn',
            'last_name' => 'Pataueg',
        ]);

        $consolidated = Requisition::query()->create([
            'reference_code' => '2026-10-0203',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $employeeRequisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-0006',
            'compiled_into_requisition_id' => $consolidated->id,
        ]);

        $issuance = Issuance::query()->create([
            'reference_code' => '2026-10-0028',
            'item_id' => \App\Models\Item::factory()->create()->id,
            'office_id' => $office->id,
            'requisition_id' => $employeeRequisition->id,
            'consolidated_requisition_id' => $consolidated->id,
            'quantity' => 5,
            'issuance_date' => now()->toDateString(),
            'issued_by' => $uc->id,
            'issued_to' => $employee->id,
        ]);

        $this->assertSame('2026-10-0203', $issuance->displayRisNumber());
        $this->assertSame('2026-0006', $issuance->displayTransactionNumber());
        $this->assertNull($employeeRequisition->reference_code);

        $hero = OwwaTransactionViewPresenter::forIssuance($issuance->fresh());
        $meta = collect($hero['meta'])->keyBy('label');

        $this->assertSame('2026-10-0203', $meta->get('RIS No.')['value']);
        $this->assertSame('2026-0006', $meta->get('Transaction No.')['value']);
    }

    public function test_direct_custodian_ris_uses_linked_reference_code(): void
    {
        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-10-0999',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $issuance = Issuance::query()->create([
            'reference_code' => '2026-10-0100',
            'item_id' => \App\Models\Item::factory()->create()->id,
            'office_id' => $office->id,
            'requisition_id' => $requisition->id,
            'quantity' => 1,
            'issuance_date' => now()->toDateString(),
            'issued_by' => $uc->id,
        ]);

        $this->assertSame('2026-10-0999', $issuance->displayRisNumber());
        $this->assertNull($issuance->displayTransactionNumber());
    }
}
