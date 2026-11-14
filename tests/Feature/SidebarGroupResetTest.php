<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Models\Office;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SidebarGroupResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_pages_clear_saved_sidebar_groups_for_the_next_sign_in(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertSee("localStorage.removeItem('collapsedGroups')", false)
            ->assertSee("sessionStorage.setItem('owwaSidebarReset', '1')", false)
            ->assertDontSee('.fi-sidebar-group.fi-collapsible', false);

        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $this->get(route('filament.system-admin.auth.login'))
            ->assertOk()
            ->assertSee("localStorage.removeItem('collapsedGroups')", false)
            ->assertSee("sessionStorage.setItem('owwaSidebarReset', '1')", false);
    }

    public function test_signed_in_pages_close_every_collapsible_sidebar_group_after_sign_in(): void
    {
        $supply = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $systemAdmin = User::factory()->create(['role' => User::ROLE_SYSTEM_ADMIN]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($supply)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('.fi-sidebar-group.fi-collapsible[data-group-label]', false)
            ->assertSee('window.owwaSidebarReset = false', false)
            ->assertSee('alpine:initialized', false);

        $this->flushSession();

        $this->actingAs($employee)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('.fi-sidebar-group.fi-collapsible[data-group-label]', false);

        $this->flushSession();

        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $this->actingAs($systemAdmin)
            ->get(Dashboard::getUrl(panel: 'system-admin'))
            ->assertOk()
            ->assertSee('.fi-sidebar-group.fi-collapsible[data-group-label]', false);
    }

    public function test_sidebar_folders_start_collapsed_when_nothing_is_saved(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $provider = new AdminPanelProvider($this->app);
        $groupsMethod = new \ReflectionMethod($provider, 'supplyNavigationGroups');
        $groups = collect($groupsMethod->invoke($provider));
        $setup = $groups->first(fn (NavigationGroup $group): bool => $group->getLabel() === 'Setup');
        $requisitions = $groups->first(fn (NavigationGroup $group): bool => $group->getLabel() === 'Requisitions');

        $this->assertInstanceOf(NavigationGroup::class, $setup);
        $this->assertTrue($setup->isCollapsed());
        $this->assertTrue($requisitions->isCollapsed());

        $systemGroups = collect(Filament::getPanel('system-admin')->getNavigationGroups());

        $this->assertTrue(
            $systemGroups->contains(fn (NavigationGroup $group): bool => $group->getLabel() === 'Setup' && $group->isCollapsed())
        );
        $this->assertTrue(
            $systemGroups->contains(fn (NavigationGroup $group): bool => $group->getLabel() === 'Monitoring' && $group->isCollapsed())
        );
    }

    public function test_sign_in_tells_the_next_sidebar_to_close_every_folder(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $user = User::factory()->withoutMultiFactorAuthentication()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email' => 'sidebar.reset@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        Livewire::test(Login::class)
            ->set('data', [
                'email' => 'sidebar.reset@example.com',
                'password' => 'password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertTrue(session('owwa_sidebar_reset') === true);

        $this->flushSession();

        $supply = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $this->actingAs($supply)
            ->withSession(['owwa_sidebar_reset' => true])
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('window.owwaSidebarReset = true', false)
            ->assertSee('.fi-sidebar-group.fi-collapsible[data-group-label]', false);
    }
}
