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

    public function test_profile_fields_are_read_only_until_edit_is_pressed(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'first_name' => 'Jane',
            'middle_name' => 'Q',
            'last_name' => 'Public',
            'email' => 'jane.public@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->assertSee('Edit')
            ->assertSee('Jane')
            ->assertSee('Public')
            ->assertSee('jane.public@example.com')
            ->assertSee('—')
            ->assertFormFieldDoesNotExist('first_name')
            ->assertFormFieldDoesNotExist('middle_name')
            ->assertFormFieldDoesNotExist('last_name')
            ->assertFormFieldDoesNotExist('gender')
            ->assertFormFieldDoesNotExist('email')
            ->assertDontSee('Save changes')
            ->call('startEditingProfile')
            ->assertFormSet([
                'first_name' => 'Jane',
                'middle_name' => 'Q',
                'last_name' => 'Public',
                'gender' => null,
            ])
            ->assertFormFieldEnabled('first_name')
            ->assertFormFieldEnabled('middle_name')
            ->assertFormFieldEnabled('last_name')
            ->assertFormFieldEnabled('gender')
            ->assertFormFieldDoesNotExist('email')
            ->assertSee('Save changes')
            ->assertSee('Cancel');
    }

    public function test_name_parts_save_and_sync_combined_name(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'first_name' => 'Jane',
            'middle_name' => 'Q',
            'last_name' => 'Public',
            'name' => 'Jane Q Public',
            'email' => 'jane.public@example.com',
            'email_verified_at' => now(),
            'password' => 'CurrentPass1',
        ]);

        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->call('startEditingProfile')
            ->fillForm([
                'first_name' => 'Janet',
                'middle_name' => 'Marie',
                'last_name' => 'Citizen',
            ])
            ->set('data.email', 'changed@example.com')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertSet('isEditingProfile', false)
            ->assertSee('Janet')
            ->assertFormFieldDoesNotExist('first_name');

        $user->refresh();

        $this->assertSame('Janet', $user->first_name);
        $this->assertSame('Marie', $user->middle_name);
        $this->assertSame('Citizen', $user->last_name);
        $this->assertSame('Janet Marie Citizen', $user->name);
        $this->assertSame('jane.public@example.com', $user->email);
    }

    public function test_cancel_discards_profile_edits(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'first_name' => 'Jane',
            'middle_name' => 'Q',
            'last_name' => 'Public',
            'name' => 'Jane Q Public',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->call('startEditingProfile')
            ->fillForm([
                'first_name' => 'Janet',
                'middle_name' => 'Marie',
                'last_name' => 'Citizen',
            ])
            ->call('cancelEditingProfile')
            ->assertSet('isEditingProfile', false)
            ->assertSee('Jane')
            ->assertSee('Public')
            ->assertFormFieldDoesNotExist('first_name')
            ->assertDontSee('Save changes');

        $user->refresh();

        $this->assertSame('Jane', $user->first_name);
        $this->assertSame('Q', $user->middle_name);
        $this->assertSame('Public', $user->last_name);
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

    public function test_gender_saves_and_can_be_cleared(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'first_name' => 'Jane',
            'middle_name' => 'Q',
            'last_name' => 'Public',
            'email' => 'jane.public@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->call('startEditingProfile')
            ->fillForm([
                'first_name' => 'Jane',
                'middle_name' => 'Q',
                'last_name' => 'Public',
                'gender' => User::GENDER_FEMALE,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('isEditingProfile', false)
            ->assertSee('Female')
            ->assertSee('avatar-female.svg')
            ->assertDontSee('ui-avatars.com');

        $this->assertSame(User::GENDER_FEMALE, $user->refresh()->gender);

        Livewire::test(EditProfile::class)
            ->call('startEditingProfile')
            ->fillForm([
                'first_name' => 'Jane',
                'middle_name' => 'Q',
                'last_name' => 'Public',
                'gender' => null,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($user->refresh()->gender);
    }

    public function test_gender_rejects_values_outside_male_and_female(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'first_name' => 'Jane',
            'last_name' => 'Public',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->call('startEditingProfile')
            ->fillForm([
                'first_name' => 'Jane',
                'last_name' => 'Public',
            ])
            ->set('data.gender', 'other')
            ->call('save')
            ->assertHasFormErrors(['gender']);

        $this->assertNull($user->refresh()->gender);
    }
}
