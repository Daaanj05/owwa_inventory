<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Models\Department;
use App\Models\Office;
use App\Models\User;
use App\Models\UserOfficeAssignment;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EditProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_employee_sees_read_only_office_and_department(): void
    {
        $office = Office::factory()->create(['name' => 'Regional Office IV-A']);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations Division',
            'code' => 'OPS',
        ]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($employee);

        Livewire::test(EditProfile::class)
            ->assertOk()
            ->assertSee('Profile')
            ->assertSee('Settings')
            ->assertSee('Organization')
            ->assertSee('Employee')
            ->assertSee('Regional Office IV-A')
            ->assertSee('Operations Division')
            ->assertSee('Contact your System Admin to change role or office assignment.')
            ->assertDontSee('Change password')
            ->assertDontSee('Account security');
    }

    public function test_unit_consolidator_sees_handled_assignments_not_single_department(): void
    {
        $office = Office::factory()->create(['name' => 'Regional Office IV-A']);
        $ops = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations Division',
            'code' => 'OPS',
        ]);
        $finance = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Finance Division',
            'code' => 'FIN',
        ]);

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $ops->id,
            'first_name' => 'Unit',
            'last_name' => 'Head',
            'email_verified_at' => now(),
        ]);

        UserOfficeAssignment::query()->create([
            'user_id' => $uc->id,
            'office_id' => $office->id,
            'department_id' => $ops->id,
        ]);
        UserOfficeAssignment::query()->create([
            'user_id' => $uc->id,
            'office_id' => $office->id,
            'department_id' => $finance->id,
        ]);

        $this->actingAs($uc);

        Livewire::test(EditProfile::class)
            ->assertOk()
            ->assertSee('Handled offices & sub-offices/departments')
            ->assertSee('Operations Division')
            ->assertSee('Finance Division')
            ->assertDontSee('Sub-Office/Department');
    }

    public function test_name_parts_save_and_sync_combined_name(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'first_name' => 'Jane',
            'middle_name' => 'Q',
            'last_name' => 'Public',
            'name' => 'Jane Q Public',
            'email_verified_at' => now(),
            'password' => 'CurrentPass1',
        ]);

        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'first_name' => 'Janet',
                'middle_name' => 'Marie',
                'last_name' => 'Citizen',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $user->refresh();

        $this->assertSame('Janet', $user->first_name);
        $this->assertSame('Marie', $user->middle_name);
        $this->assertSame('Citizen', $user->last_name);
        $this->assertSame('Janet Marie Citizen', $user->name);
    }

    public function test_supply_custodian_sees_office_on_profile(): void
    {
        $office = Office::factory()->create(['name' => 'OWWA Central']);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($custodian);

        Livewire::test(EditProfile::class)
            ->assertSee('Supply Custodian')
            ->assertSee('OWWA Central');
    }
}
