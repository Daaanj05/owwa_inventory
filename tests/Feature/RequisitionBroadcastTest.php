<?php

namespace Tests\Feature;

use App\Events\RequisitionChanged;
use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class RequisitionBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_requisition_dispatches_requisition_changed_event(): void
    {
        Event::fake([RequisitionChanged::class]);

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        Event::assertDispatched(RequisitionChanged::class, function (RequisitionChanged $event): bool {
            return $event->action === 'created'
                && $event->requisition->status === Requisition::STATUS_PENDING;
        });
    }

    public function test_employee_requisition_broadcasts_to_office_and_user_channels(): void
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
        ]);

        $event = new RequisitionChanged($requisition, 'created');
        $channelNames = collect($event->broadcastOn())
            ->map(fn (PrivateChannel $channel): string => $channel->name)
            ->all();

        $this->assertContains('private-requisitions.office.'.$office->id, $channelNames);
        $this->assertContains('private-requisitions.user.'.$employee->id, $channelNames);
        $this->assertNotContains('private-requisitions.custodian', $channelNames);
    }

    public function test_unit_consolidator_requisition_broadcasts_to_custodian_channel(): void
    {
        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $event = new RequisitionChanged($requisition, 'created');
        $channelNames = collect($event->broadcastOn())
            ->map(fn (PrivateChannel $channel): string => $channel->name)
            ->all();

        $this->assertContains('private-requisitions.custodian', $channelNames);
        $this->assertContains('private-requisitions.office.'.$office->id, $channelNames);
        $this->assertContains('private-requisitions.user.'.$uc->id, $channelNames);
    }

    public function test_office_channel_authorization(): void
    {
        $office = Office::factory()->create();
        $otherOffice = Office::factory()->create();

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $outsider = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $otherOffice->id,
        ]);

        $callback = $this->channelCallback('requisitions.office.{officeId}');

        $this->assertTrue($callback($uc, $office->id));
        $this->assertTrue($callback($employee, $office->id));
        $this->assertFalse($callback($outsider, $office->id));
    }

    public function test_custodian_channel_authorization(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $callback = $this->channelCallback('requisitions.custodian');

        $this->assertTrue($callback($custodian));
        $this->assertFalse($callback($employee));
    }

    public function test_user_channel_authorization(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $other = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $callback = $this->channelCallback('requisitions.user.{userId}');

        $this->assertTrue($callback($employee, $employee->id));
        $this->assertFalse($callback($employee, $other->id));
    }

    public function test_list_requisitions_refresh_handler_resets_without_error(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $this->actingAs($uc);

        Livewire::test(ListRequisitions::class)
            ->call('refreshFromRequisitionBroadcast')
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
