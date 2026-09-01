<?php

namespace Tests\Unit;

use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Support\EmployeeRequisitionViewPresenter;
use App\Support\RequisitionViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionViewPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_steps_pending_shows_active_review(): void
    {
        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0001',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $steps = RequisitionViewPresenter::workflowSteps($requisition);

        $this->assertSame('active', $steps[1]['state']);
        $this->assertSame('active', $steps[2]['state']);
    }

    public function test_for_record_includes_reference_label(): void
    {
        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0099',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $hero = RequisitionViewPresenter::forRecord($requisition);

        $this->assertSame('2026-01-0099', $hero['reference']);
        $this->assertSame('RIS No.', $hero['referenceLabel']);
        $this->assertCount(4, $hero['workflowSteps']);
    }

    public function test_employee_record_uses_transaction_number_and_employee_workflow(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
            'transaction_number' => '2026-01-EMP-01',
        ]);

        $hero = RequisitionViewPresenter::forRecord($requisition);

        $this->assertSame('2026-01-EMP-01', $hero['reference']);
        $this->assertSame('Transaction No.', $hero['referenceLabel']);
        $this->assertSame('Pending UC review', $hero['statusLabel']);
        $this->assertSame('Submitted', $hero['workflowSteps'][0]['label']);
    }

    public function test_employee_workflow_steps_include_dates_when_available(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'transaction_number' => '2026-01-EMP-02',
            'approved_at' => now(),
            'endorsed_at' => now(),
            'compiled_into_requisition_id' => Requisition::query()->create([
                'office_id' => $office->id,
                'requested_by' => User::factory()->create([
                    'role' => User::ROLE_UNIT_CONSOLIDATOR,
                    'office_id' => $office->id,
                ])->id,
                'status' => Requisition::STATUS_PENDING,
                'reference_code' => '2026-01-UC-01',
            ])->id,
        ]);

        $steps = EmployeeRequisitionViewPresenter::workflowSteps($requisition);

        $this->assertStringContainsString('Request filed', $steps[0]['description']);
        $this->assertStringContainsString('Reviewed by consolidator', $steps[1]['description']);
        $this->assertStringContainsString('Sent to Supply Custodian', $steps[2]['description']);
    }
}
