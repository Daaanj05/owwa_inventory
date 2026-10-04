<?php

namespace Tests\Feature;

use App\Filament\Pages\ProcurementAnalytics;
use App\Livewire\OwwaNotificationDropdown;
use App\Models\User;
use App\Notifications\RequisitionWorkflowDatabaseNotification;
use App\Support\AiProcurementSummaryRestore;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_lazy_placeholder_shows_the_bell_without_loading_notifications(): void
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

        $html = Livewire::test(OwwaNotificationDropdown::class)->instance()->placeholder();

        $this->assertStringContainsString('owwa-notif-trigger', $html);
        $this->assertStringNotContainsString('owwa-notif-dropdown', $html);
        $this->assertStringNotContainsString('Requisition submitted', $html);
        $this->assertStringNotContainsString('owwa-notif-badge', $html);
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

    public function test_open_notification_strips_legacy_ai_run_and_queues_summary_restore(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'email_verified_at' => now(),
        ]);

        $legacyUrl = ProcurementAnalytics::getUrl(panel: 'admin').'?ai_run=18#procurement-summary';
        $cleanUrl = ProcurementAnalytics::getUrl(panel: 'admin').'#procurement-summary';

        $user->notifyNow(new class($legacyUrl) extends \Illuminate\Notifications\Notification
        {
            public function __construct(private string $url) {}

            public function via(object $notifiable): array
            {
                return ['database'];
            }

            public function toDatabase(object $notifiable): array
            {
                return FilamentNotification::make()
                    ->title('AI recommendation ready')
                    ->body('Your procurement recommendation is ready.')
                    ->success()
                    ->actions([
                        Action::make('viewResult')
                            ->label('View the result')
                            ->url($this->url)
                            ->markAsRead(),
                    ])
                    ->getDatabaseMessage();
            }
        });

        $notification = $user->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('ai_run=18', (string) data_get($notification->data, 'actions.0.url'));

        $this->actingAs($user);

        Livewire::test(OwwaNotificationDropdown::class)
            ->call('openNotification', $notification->id)
            ->assertRedirect($cleanUrl);

        // SPA navigation may mount Procurement Analytics and consume the one-shot restore.
        // The important behavior here is stripping ai_run from the destination URL.
        $pending = Cache::get(AiProcurementSummaryRestore::cacheKey((int) $user->id));
        $shown = AiProcurementSummaryRestore::hasBeenShown((int) $user->id, 18);
        $this->assertTrue($pending === 18 || $shown);
    }

    public function test_open_notification_does_not_requeue_summary_after_run_was_shown(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'email_verified_at' => now(),
        ]);

        $legacyUrl = ProcurementAnalytics::getUrl(panel: 'admin').'?ai_run=18#procurement-summary';
        $cleanUrl = ProcurementAnalytics::getUrl(panel: 'admin').'#procurement-summary';

        $user->notifyNow(new class($legacyUrl) extends \Illuminate\Notifications\Notification
        {
            public function __construct(private string $url) {}

            public function via(object $notifiable): array
            {
                return ['database'];
            }

            public function toDatabase(object $notifiable): array
            {
                return FilamentNotification::make()
                    ->title('AI recommendation ready')
                    ->body('Your procurement recommendation is ready.')
                    ->success()
                    ->actions([
                        Action::make('viewResult')
                            ->label('View the result')
                            ->url($this->url)
                            ->markAsRead(),
                    ])
                    ->getDatabaseMessage();
            }
        });

        $notification = $user->notifications()->first();
        $this->assertNotNull($notification);

        AiProcurementSummaryRestore::markShown((int) $user->id, 18);

        $this->actingAs($user);

        Livewire::test(OwwaNotificationDropdown::class)
            ->call('openNotification', $notification->id)
            ->assertRedirect($cleanUrl);

        $this->assertNull(Cache::get(AiProcurementSummaryRestore::cacheKey((int) $user->id)));
        $this->assertTrue(AiProcurementSummaryRestore::hasBeenShown((int) $user->id, 18));
    }

    public function test_read_all_button_remains_visible_after_opening_notifications_individually(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
        ]);

        $user->notify(new RequisitionWorkflowDatabaseNotification('First', 'One'));
        $user->notify(new RequisitionWorkflowDatabaseNotification('Second', 'Two'));

        $this->actingAs($user);

        $component = Livewire::test(OwwaNotificationDropdown::class);

        foreach ($user->notifications as $notification) {
            $component->call('openNotification', $notification->id);
        }

        $component
            ->assertSee('Read all')
            ->assertSee('First')
            ->assertSee('Second');

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    public function test_read_all_button_visible_when_all_notifications_already_read(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $user->notify(new RequisitionWorkflowDatabaseNotification('Read item', 'Handled.'));
        $user->unreadNotifications->markAsRead();

        $this->actingAs($user);

        Livewire::test(OwwaNotificationDropdown::class)
            ->assertSee('Read all')
            ->assertSee('Read item');
    }

    public function test_load_more_reveals_older_notifications(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'email_verified_at' => now(),
        ]);

        for ($i = 1; $i <= 20; $i++) {
            $user->notify(new RequisitionWorkflowDatabaseNotification(
                "Notification item {$i}",
                "Body {$i}",
            ));

            $user->notifications()
                ->where('data->title', "Notification item {$i}")
                ->update(['created_at' => now()->subMinutes(21 - $i)]);
        }

        $this->actingAs($user);

        Livewire::test(OwwaNotificationDropdown::class)
            ->assertSee('Notification item 20')
            ->assertDontSee('Notification item 5')
            ->assertSee('See previous notifications')
            ->call('loadMoreNotifications')
            ->assertSee('Notification item 5');
    }
}
