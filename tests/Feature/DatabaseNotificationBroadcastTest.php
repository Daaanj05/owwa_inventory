<?php

namespace Tests\Feature;

use App\Events\DatabaseNotificationsSentNow;
use App\Listeners\BroadcastDatabaseNotificationsSent;
use App\Livewire\OwwaNotificationDropdown;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Notifications\RequisitionRejectedMailNotification;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use Filament\Facades\Filament;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class DatabaseNotificationBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_is_registered_for_notification_sent(): void
    {
        Event::fake();

        Event::assertListening(
            NotificationSent::class,
            BroadcastDatabaseNotificationsSent::class,
        );
    }

    public function test_database_channel_dispatches_per_user_sent_event(): void
    {
        Event::fake([DatabaseNotificationsSentNow::class]);

        config()->set('filament.broadcasting.echo', [
            'broadcaster' => 'pusher',
            'key' => 'test-key',
            'cluster' => 'ap1',
            'forceTLS' => true,
        ]);

        $recipient = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);
        $other = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $notification = new RequisitionWorkflowDatabaseNotification(
            'Requisition submitted',
            'Needs review.',
        );

        app(BroadcastDatabaseNotificationsSent::class)->handle(
            new NotificationSent($recipient, $notification, 'database'),
        );

        Event::assertDispatched(DatabaseNotificationsSentNow::class, function (DatabaseNotificationsSentNow $event) use ($recipient): bool {
            return (int) $event->user->id === (int) $recipient->id;
        });

        Event::assertNotDispatched(DatabaseNotificationsSentNow::class, function (DatabaseNotificationsSentNow $event) use ($other): bool {
            return (int) $event->user->id === (int) $other->id;
        });
    }

    public function test_sent_event_broadcasts_on_user_private_channel_only(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $event = new DatabaseNotificationsSentNow($user);
        $channelNames = collect($event->broadcastOn())
            ->map(fn (PrivateChannel $channel): string => $channel->name)
            ->all();

        $this->assertSame(['private-App.Models.User.'.$user->id], $channelNames);
        $this->assertSame('database-notifications.sent', $event->broadcastAs());
        $this->assertSame([], $event->broadcastWith());
        $this->assertNotContains('private-requisitions.office.1', $channelNames);
        $this->assertNotContains('private-requisitions.custodian', $channelNames);
    }

    public function test_mail_channel_does_not_dispatch_sent_event(): void
    {
        Event::fake([DatabaseNotificationsSentNow::class]);

        config()->set('filament.broadcasting.echo', [
            'broadcaster' => 'pusher',
            'key' => 'test-key',
            'cluster' => 'ap1',
            'forceTLS' => true,
        ]);

        $office = Office::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $user->id,
            'status' => Requisition::STATUS_REJECTED,
        ]);

        $notification = new RequisitionRejectedMailNotification($requisition, 'Requisition rejected');

        app(BroadcastDatabaseNotificationsSent::class)->handle(
            new NotificationSent($user, $notification, 'mail'),
        );

        Event::assertNotDispatched(DatabaseNotificationsSentNow::class);
    }

    public function test_dropdown_skips_polling_when_echo_key_is_configured(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config()->set('filament.broadcasting.echo', [
            'broadcaster' => 'pusher',
            'key' => 'test-key',
            'cluster' => 'ap1',
            'forceTLS' => true,
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $component = Livewire::test(OwwaNotificationDropdown::class);
        $this->assertNull($component->instance()->getPollingInterval());
    }

    public function test_dropdown_polls_when_echo_key_is_missing(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config()->set('filament.broadcasting.echo', []);

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $component = Livewire::test(OwwaNotificationDropdown::class);
        $this->assertSame('30s', $component->instance()->getPollingInterval());
    }
}
