<?php

namespace Tests\Unit;

use App\Filament\Resources\PropertyActionRequests\Schemas\PropertyActionRequestInfolistSchema;
use App\Models\PropertyActionRequest;
use App\Models\User;
use App\Support\PropertyActionRequestViewPresenter;
use Illuminate\Database\Eloquent\Collection;
use ReflectionMethod;
use Tests\TestCase;

class PropertyActionRequestEmployeeViewTest extends TestCase
{
    public function test_employee_return_header_does_not_repeat_the_requester_as_accountable_uc(): void
    {
        $labels = collect(PropertyActionRequestViewPresenter::forRecord($this->employeeReturn())['meta'])
            ->pluck('label');

        $this->assertFalse($labels->contains('Accountable UC'));
        $this->assertTrue($labels->contains('Requested by'));
        $this->assertTrue($labels->contains('Office'));
    }

    public function test_non_employee_return_header_keeps_accountable_uc(): void
    {
        $record = $this->employeeReturn();
        $record->requestedBy->role = User::ROLE_UNIT_CONSOLIDATOR;

        $labels = collect(PropertyActionRequestViewPresenter::forRecord($record)['meta'])
            ->pluck('label');

        $this->assertTrue($labels->contains('Accountable UC'));
    }

    public function test_employee_return_details_omit_fields_already_shown_in_the_header(): void
    {
        $record = $this->employeeReturn();
        $hideOnEmployeeRequest = new ReflectionMethod(PropertyActionRequestInfolistSchema::class, 'hideOnEmployeeRequest');
        $showApprovalField = new ReflectionMethod(PropertyActionRequestInfolistSchema::class, 'showApprovalField');

        $this->assertFalse($hideOnEmployeeRequest->invoke(null)($record));
        $this->assertFalse($showApprovalField->invoke(null, $record, false));
        $this->assertTrue($showApprovalField->invoke(null, $record, true));
    }

    public function test_non_employee_return_details_keep_the_full_field_set(): void
    {
        $record = $this->employeeReturn();
        $record->requestedBy->role = User::ROLE_UNIT_CONSOLIDATOR;
        $hideOnEmployeeRequest = new ReflectionMethod(PropertyActionRequestInfolistSchema::class, 'hideOnEmployeeRequest');
        $showApprovalField = new ReflectionMethod(PropertyActionRequestInfolistSchema::class, 'showApprovalField');

        $this->assertTrue($hideOnEmployeeRequest->invoke(null)($record));
        $this->assertTrue($showApprovalField->invoke(null, $record, false));
    }

    protected function employeeReturn(): PropertyActionRequest
    {
        $employee = new User([
            'name' => 'Jennilyn Buenavente Pataueg',
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $employee->id = 14;

        $record = new PropertyActionRequest([
            'reference_code' => '2026-10-0002',
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'reason_detail' => 'Return of item; not needed anymore',
            'requested_by' => 14,
            'accountable_user_id' => 14,
            'status' => PropertyActionRequest::STATUS_PENDING_UC,
            'office_id' => 1,
            'department_id' => 3,
        ]);
        $record->setRelation('requestedBy', $employee);
        $record->setRelation('accountableUser', $employee);
        $record->setRelation('office', null);
        $record->setRelation('department', null);
        $record->setRelation('lines', new Collection);

        return $record;
    }
}
