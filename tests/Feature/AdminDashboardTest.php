<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_supply_custodian_can_load_admin_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $this->get('/admin')->assertOk();

        Livewire::test(Dashboard::class)->assertOk();
    }

    public function test_system_admin_cannot_access_admin_panel_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $this->get('/admin')->assertForbidden();
    }
}
