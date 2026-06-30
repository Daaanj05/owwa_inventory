<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\ChangePassword;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Office;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ForcePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_created_user_has_must_change_password_flag(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $office = Office::factory()->create();
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->fillForm([
                'first_name' => 'New',
                'last_name' => 'User',
                'email' => 'new.user@example.com',
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $office->id,
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas(User::class, [
            'email' => 'new.user@example.com',
            'must_change_password' => true,
        ]);
    }

    public function test_user_with_must_change_password_is_redirected_from_dashboard(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->mustChangePassword()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/admin')
            ->assertRedirect(ChangePassword::getUrl(panel: 'admin'));
    }

    public function test_user_with_must_change_password_is_redirected_from_profile(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->mustChangePassword()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/admin/profile')
            ->assertRedirect(ChangePassword::getUrl(panel: 'admin'));
    }

    public function test_change_password_page_rejects_weak_password(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->mustChangePassword()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
            'password' => 'TempPass1',
        ]);

        $this->actingAs($user);

        Livewire::test(ChangePassword::class)
            ->fillForm([
                'password' => 'weakpass',
                'passwordConfirmation' => 'weakpass',
            ])
            ->call('changePassword')
            ->assertHasFormErrors(['password']);
    }

    public function test_change_password_clears_flag_and_allows_dashboard_access(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->mustChangePassword()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
            'password' => 'TempPass1',
        ]);

        $this->actingAs($user);

        Livewire::test(ChangePassword::class)
            ->fillForm([
                'password' => 'NewPass1',
                'passwordConfirmation' => 'NewPass1',
            ])
            ->call('changePassword')
            ->assertHasNoFormErrors()
            ->assertNotified('Password updated');

        $user->refresh();

        $this->assertFalse($user->mustChangePassword());
        $this->assertTrue(Hash::check('NewPass1', $user->password));

        $this->get('/admin')->assertOk();
    }

    public function test_verified_user_can_access_profile_when_flag_is_false(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
            'must_change_password' => false,
        ]);

        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->assertOk()
            ->assertSee('Account settings')
            ->assertSee('Back')
            ->assertSee('Profile information')
            ->assertSee('Change password');
    }

    public function test_profile_rejects_new_password_without_current_password(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
            'password' => 'CurrentPass1',
        ]);

        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'NewPass1',
                'passwordConfirmation' => 'NewPass1',
            ])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);
    }

    public function test_profile_updates_password_with_valid_current_password(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
            'password' => 'CurrentPass1',
        ]);

        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'NewPass1',
                'passwordConfirmation' => 'NewPass1',
                'currentPassword' => 'CurrentPass1',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $user->refresh();

        $this->assertFalse($user->mustChangePassword());
        $this->assertTrue(Hash::check('NewPass1', $user->password));
    }

    public function test_system_admin_can_access_profile_on_system_admin_panel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $user = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->assertOk();
    }
}
