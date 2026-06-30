<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Office;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_user_from_actions_menu_shows_form_fields(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $office = Office::factory()->create();
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('edit')->table($employee))
            ->assertFormFieldExists('first_name')
            ->assertFormFieldExists('email')
            ->assertFormFieldExists('role');
    }

    public function test_edit_user_from_view_modal_footer_shows_form_fields(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $office = Office::factory()->create();
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('view')->table($employee))
            ->callAction(TestAction::make('edit'))
            ->assertFormFieldExists('first_name')
            ->assertFormFieldExists('email')
            ->assertFormFieldExists('office_id');
    }
}
