<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Models\Office;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarSignOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_employee_sees_sidebar_sign_out(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('owwa-sidebar-sign-out', false)
            ->assertSee('Sign out');
    }

    public function test_unit_consolidator_sees_sidebar_sign_out(): void
    {
        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $this->actingAs($uc)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('owwa-sidebar-sign-out', false)
            ->assertSee('Sign out');
    }

    public function test_supply_custodian_does_not_see_sidebar_sign_out(): void
    {
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $this->actingAs($custodian)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertDontSee('owwa-sidebar-sign-out', false);
    }

    public function test_employee_sidebar_sign_out_posts_to_logout_route(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $logoutUrl = Filament::getLogoutUrl();

        $this->actingAs($employee)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('action="'.$logoutUrl.'"', false)
            ->assertSee('method="post"', false);
    }
}
