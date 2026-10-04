<?php

namespace Tests\Feature;

use App\Events\PropertyActionRequestChanged;
use App\Filament\Resources\PropertyActionRequests\Pages\ListPropertyActionRequests;
use App\Models\Department;
use App\Models\Office;
use App\Models\PropertyActionRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class PropertyActionRequestBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filament.broadcasting.echo', [
            'broadcaster' => 'pusher',
            'key' => 'test-key',
            'cluster' => 'ap1',
            'forceTLS' => true,
        ]);
    }

    public function test_creating_property_action_request_survives_broadcast_transport_failure(): void
    {
        Event::listen(PropertyActionRequestChanged::class, function (): void {
            throw new \Illuminate\Broadcasting\BroadcastException('Pusher error: connection refused');
        });

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $request = PropertyActionRequest::query()->create([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $employee->id,
            'office_id' => $office->id,
            'status' => PropertyActionRequest::STATUS_DRAFT,
        ]);

        $this->assertDatabaseHas(PropertyActionRequest::class, [
            'id' => $request->id,
            'status' => PropertyActionRequest::STATUS_DRAFT,
        ]);
    }

    public function test_creating_property_action_request_dispatches_changed_event(): void
    {
        Event::fake([PropertyActionRequestChanged::class]);

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        PropertyActionRequest::query()->create([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $employee->id,
            'office_id' => $office->id,
            'status' => PropertyActionRequest::STATUS_PENDING_UC,
        ]);

        Event::assertDispatched(PropertyActionRequestChanged::class, function (PropertyActionRequestChanged $event): bool {
            return $event->action === 'created'
                && $event->propertyActionRequest->status === PropertyActionRequest::STATUS_PENDING_UC;
        });
    }

    public function test_updating_property_action_request_dispatches_changed_event(): void
    {
        Event::fake([PropertyActionRequestChanged::class]);

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $request = PropertyActionRequest::query()->create([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $employee->id,
            'office_id' => $office->id,
            'status' => PropertyActionRequest::STATUS_DRAFT,
        ]);

        Event::fake([PropertyActionRequestChanged::class]);

        $request->update(['status' => PropertyActionRequest::STATUS_PENDING_UC]);

        Event::assertDispatched(PropertyActionRequestChanged::class, function (PropertyActionRequestChanged $event): bool {
            return $event->action === 'updated'
                && $event->propertyActionRequest->status === PropertyActionRequest::STATUS_PENDING_UC;
        });
    }

    public function test_employee_pending_uc_broadcasts_to_office_and_user_not_custodian(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $request = PropertyActionRequest::query()->create([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $employee->id,
            'office_id' => $office->id,
            'status' => PropertyActionRequest::STATUS_PENDING_UC,
        ]);

        $event = new PropertyActionRequestChanged($request, 'updated');
        $channelNames = collect($event->broadcastOn())
            ->map(fn (PrivateChannel $channel): string => $channel->name)
            ->all();

        $this->assertContains('private-property-actions.office.'.$office->id, $channelNames);
        $this->assertContains('private-property-actions.user.'.$employee->id, $channelNames);
        $this->assertNotContains('private-property-actions.custodian', $channelNames);
    }

    public function test_pending_sc_broadcasts_to_custodian_channel(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $request = PropertyActionRequest::query()->create([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $employee->id,
            'accountable_user_id' => $employee->id,
            'office_id' => $office->id,
            'status' => PropertyActionRequest::STATUS_PENDING_SC,
        ]);

        $event = new PropertyActionRequestChanged($request, 'updated');
        $channelNames = collect($event->broadcastOn())
            ->map(fn (PrivateChannel $channel): string => $channel->name)
            ->all();

        $this->assertContains('private-property-actions.custodian', $channelNames);
        $this->assertContains('private-property-actions.office.'.$office->id, $channelNames);
        $this->assertContains('private-property-actions.user.'.$employee->id, $channelNames);
    }

    public function test_unit_consolidator_request_broadcasts_to_custodian_channel(): void
    {
        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $request = PropertyActionRequest::query()->create([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'good_condition',
            'requested_by' => $uc->id,
            'accountable_user_id' => $uc->id,
            'office_id' => $office->id,
            'status' => PropertyActionRequest::STATUS_PENDING_SC,
        ]);

        $event = new PropertyActionRequestChanged($request, 'created');
        $channelNames = collect($event->broadcastOn())
            ->map(fn (PrivateChannel $channel): string => $channel->name)
            ->all();

        $this->assertContains('private-property-actions.custodian', $channelNames);
        $this->assertContains('private-property-actions.office.'.$office->id, $channelNames);
        $this->assertContains('private-property-actions.user.'.$uc->id, $channelNames);
    }

    public function test_office_channel_authorization(): void
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations',
            'code' => 'OPS',
        ]);

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $otherOffice = Office::factory()->create();
        $outsider = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $otherOffice->id,
        ]);

        $callback = $this->channelCallback('property-actions.office.{officeId}');

        $this->assertTrue($callback($uc, $office->id));
        $this->assertTrue($callback($employee, $office->id));
        $this->assertFalse($callback($outsider, $office->id));
    }

    public function test_custodian_channel_authorization(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $callback = $this->channelCallback('property-actions.custodian');

        $this->assertTrue($callback($custodian));
        $this->assertFalse($callback($employee));
    }

    public function test_user_channel_authorization(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $other = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $callback = $this->channelCallback('property-actions.user.{userId}');

        $this->assertTrue($callback($employee, $employee->id));
        $this->assertFalse($callback($employee, $other->id));
    }

    public function test_list_property_action_requests_refresh_handler_succeeds(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $this->actingAs($uc);

        Livewire::test(ListPropertyActionRequests::class)
            ->call('refreshFromPropertyActionBroadcast')
            ->assertOk();
    }

    /**
     * @return callable(mixed...): bool
     */
    protected function channelCallback(string $pattern): callable
    {
        $channels = Broadcast::connection()->getChannels();

        $this->assertTrue($channels->has($pattern), "Channel [{$pattern}] is not registered.");

        return $channels->get($pattern);
    }
}
