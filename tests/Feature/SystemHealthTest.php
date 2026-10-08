<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemHealth;
use App\Models\SystemHealthSnapshot;
use App\Models\User;
use App\Models\UserLog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_can_view_system_health_page(): void
    {
        config([
            'mail.default' => 'log',
            'inventory.health_active_session_minutes' => 15,
            'session.lifetime' => 120,
            'session.driver' => 'file',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'name' => 'Ada Admin',
            'email_verified_at' => now(),
        ]);

        $staleUser = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'name' => 'Prof. Rebekah Vandervort MD',
            'email_verified_at' => now(),
        ]);

        UserLog::query()->create([
            'user_id' => $admin->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'path' => '/system-admin',
            'panel' => 'system-admin',
            'logged_in_at' => now()->subMinutes(5),
            'last_activity_at' => now()->subMinute(),
        ]);

        UserLog::query()->create([
            'user_id' => $staleUser->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'path' => '/admin',
            'panel' => 'admin',
            'logged_in_at' => now()->subMonths(2),
            'last_activity_at' => now()->subMonths(2),
        ]);

        SystemHealthSnapshot::query()->create([
            'captured_at' => now()->subMinutes(5),
            'open_sessions' => 1,
            'active_sessions' => 1,
            'checks_ok' => true,
            'checks_json' => [],
        ]);

        $this->actingAs($admin);

        $this->get('/system-admin/system-health')->assertOk();

        $this->assertNotNull(
            UserLog::query()
                ->where('user_id', $staleUser->id)
                ->whereNotNull('logged_out_at')
                ->first()
        );

        $component = Livewire::test(SystemHealth::class)
            ->assertOk()
            ->assertSee('System health')
            ->assertSee('Live checks')
            ->assertSee('System healthy')
            ->assertSee('live checks passed')
            ->assertDontSee('All systems OK')
            ->assertSee('Using now')
            ->assertSee('View list')
            ->assertSee('Scheduler heartbeat every 15 min')
            ->assertDontSee('View who’s online')
            ->assertDontSee('Browser sessions')
            ->assertSee('Mail: Log only (not emailed)')
            ->assertSee('Capture snapshot')
            ->assertDontSee('Active by role')
            ->assertDontSee('Active by panel')
            ->assertDontSee('Active by portal')
            ->assertDontSee('Prof. Rebekah Vandervort MD')
            ->assertDontSee('Who is online')
            ->assertActionExists('viewStatusOverview')
            ->mountAction('viewStatusOverview')
            ->assertActionMounted('viewStatusOverview');

        $modalHtml = (string) $component->instance()->getMountedAction()?->getModalContent();

        $this->assertStringContainsString('Who is using the system now', $modalHtml);
        $this->assertStringContainsString('Active by role', $modalHtml);
        $this->assertStringContainsString('Active by portal', $modalHtml);
        $this->assertStringContainsString('Ada Admin', $modalHtml);
        $this->assertStringContainsString('System admin', $modalHtml);
        $this->assertStringNotContainsString('Prof. Rebekah Vandervort MD', $modalHtml);

        $component->assertMountedActionModalSee('Open login history');
    }

    public function test_non_system_admin_cannot_access_system_health_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($employee);

        $this->get('/system-admin/system-health')->assertRedirect();

        $this->assertFalse(SystemHealth::canAccess());
    }

    public function test_up_route_returns_ok_when_database_is_healthy(): void
    {
        $this->get('/up')->assertOk();
    }
}
