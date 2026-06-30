<?php

namespace Tests\Feature;

use App\Livewire\OwwaNotificationDropdown;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OwwaNotificationDropdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_dropdown_lists_notification_with_unread_badge(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'email_verified_at' => now(),
        ]);

        $user->notify(new RequisitionWorkflowDatabaseNotification(
            'Requisition submitted',
            'A new requisition needs your review.',
        ));

        $this->actingAs($user);

        Livewire::test(OwwaNotificationDropdown::class)
            ->assertSee('Notifications')
            ->assertSee('Requisition submitted')
            ->assertSee('A new requisition needs your review.')
            ->assertSee('1');
    }

    public function test_unread_tab_hides_read_notifications(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'email_verified_at' => now(),
        ]);

        $user->notify(new RequisitionWorkflowDatabaseNotification('Unread alert', 'Still pending.'));
        $user->notify(new RequisitionWorkflowDatabaseNotification('Read alert', 'Already handled.'));

        $user->notifications()
            ->get()
            ->first(fn ($notification): bool => ($notification->data['title'] ?? '') === 'Read alert')
            ?->markAsRead();

        $this->actingAs($user);

        Livewire::test(OwwaNotificationDropdown::class)
            ->call('setTab', 'unread')
            ->assertSee('Unread alert')
            ->assertDontSee('Read alert');
    }

    public function test_mark_all_as_read_clears_unread_count(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'email_verified_at' => now(),
        ]);

        $user->notify(new RequisitionWorkflowDatabaseNotification('One', 'First'));
        $user->notify(new RequisitionWorkflowDatabaseNotification('Two', 'Second'));

        $this->actingAs($user);

        Livewire::test(OwwaNotificationDropdown::class)
            ->call('markAllNotificationsAsRead');

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    public function test_open_notification_marks_it_as_read(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'email_verified_at' => now(),
        ]);

        $user->notify(new RequisitionWorkflowDatabaseNotification('Open me', 'Details here.'));

        $notification = $user->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertNull($notification->read_at);

        $this->actingAs($user);

        Livewire::test(OwwaNotificationDropdown::class)
            ->call('openNotification', $notification->id);

        $this->assertNotNull($notification->fresh()->read_at);
    }
}
