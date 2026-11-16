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
    public function test_return_header_leaves_identity_fields_to_the_details_grid(): void
    {
        $labels = collect(PropertyActionRequestViewPresenter::forRecord($this->employeeReturn())['meta'])
            ->pluck('label');

        $this->assertFalse($labels->contains('Accountable UC'));
        $this->assertFalse($labels->contains('Requested by'));
        $this->assertFalse($labels->contains('Office'));
    }

    public function test_employee_return_details_hide_accountable_uc_when_it_is_the_requester(): void
    {
        $record = $this->employeeReturn();
        $showAccountableUser = new ReflectionMethod(PropertyActionRequestInfolistSchema::class, 'showAccountableUser');

        $this->assertFalse($showAccountableUser->invoke(null, $record));
    }

    public function test_compiled_return_details_keep_accountable_uc_and_hide_empty_sc_fields(): void
    {
        $record = $this->employeeReturn();
        $record->requestedBy->role = User::ROLE_UNIT_CONSOLIDATOR;
        $record->status = PropertyActionRequest::STATUS_PENDING_SC;
        $showAccountableUser = new ReflectionMethod(PropertyActionRequestInfolistSchema::class, 'showAccountableUser');

        $this->assertTrue($showAccountableUser->invoke(null, $record));
        $this->assertNull($record->sc_approved_by);
        $this->assertNull($record->sc_approved_at);
        $this->assertNull($record->executed_at);
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
            'reason_code' => 'serviceable_not_needed',
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
