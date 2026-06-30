<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_custodian_on_system_admin_is_logged_out_and_redirected(): void
    {
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($custodian)
            ->get('/system-admin')
            ->assertRedirect('/system-admin/login')
            ->assertSessionHas(
                'panel_access_error',
                'This portal is for system administrators only. Sign in at the operations portal if you are a supply custodian, unit consolidator, or employee.'
            );

        $this->assertGuest();
    }

    public function test_system_admin_on_operations_panel_is_logged_out_and_redirected(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
        ]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertRedirect('/admin/login')
            ->assertSessionHas(
                'panel_access_error',
                'This portal is for supply custodian, unit consolidator, and employee accounts. System administrators must use the system administrator portal.'
            );

        $this->assertGuest();
    }

    public function test_login_pages_do_not_show_cross_panel_links(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('OWWA-4A personnel only. Unauthorized access is strictly prohibited.', false)
            ->assertDontSee('System administrator portal', false)
            ->assertDontSee('Operations portal', false)
            ->assertDontSee('Use this only if you were sent to the wrong portal.', false);

        $this->get('/system-admin/login')
            ->assertOk()
            ->assertSee('OWWA-4A personnel only. Unauthorized access is strictly prohibited.', false)
            ->assertDontSee('System administrator portal', false)
            ->assertDontSee('Operations portal', false)
            ->assertDontSee('Use this only if you were sent to the wrong portal.', false);
    }
}
