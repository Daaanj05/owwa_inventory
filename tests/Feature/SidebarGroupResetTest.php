<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee("sessionStorage.getItem('owwaSidebarReset')", false);

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
}
