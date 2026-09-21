<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Department;
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

    public function test_users_table_shows_verification_status(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);
        $verifiedEmployee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'email_verified_at' => now(),
        ]);
        $pendingEmployee = User::factory()->unverified()->create([
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$verifiedEmployee, $pendingEmployee])
            ->assertTableColumnStateSet('email_verified_at', 'Verified', $verifiedEmployee)
            ->assertTableColumnStateSet('email_verified_at', 'Pending', $pendingEmployee);
    }

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

    public function test_creating_employee_without_department_fails_validation(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $office = Office::factory()->create(['name' => 'Field Office']);
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->fillForm([
                'first_name' => 'No',
                'last_name' => 'Department',
                'email' => 'employee.no.dept@example.com',
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $office->id,
                'department_id' => null,
            ])
            ->callMountedAction()
            ->assertHasFormErrors(['department_id' => 'required']);

        $this->assertDatabaseMissing(User::class, [
            'email' => 'employee.no.dept@example.com',
        ]);
    }

    public function test_creating_supply_custodian_rejects_non_regional_supply_office(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $regional = Office::factory()->create([
            'name' => 'Regional Supply',
            'is_regional_supply' => true,
        ]);
        $otherOffice = Office::factory()->create([
            'name' => 'Field Office',
            'is_regional_supply' => false,
        ]);
        $department = Department::query()->create([
            'office_id' => $otherOffice->id,
            'name' => 'Admin',
            'code' => 'ADM',
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->fillForm([
                'first_name' => 'Supply',
                'last_name' => 'Custodian',
                'email' => 'custodian.nonregional@example.com',
                'role' => User::ROLE_SUPPLY_CUSTODIAN,
                'office_id' => $otherOffice->id,
                'department_id' => $department->id,
            ])
            ->callMountedAction()
            ->assertHasFormErrors(['office_id']);

        $this->assertDatabaseMissing(User::class, [
            'email' => 'custodian.nonregional@example.com',
        ]);
        $this->assertNotNull($regional->fresh());
    }

    public function test_creating_supply_custodian_with_regional_office_and_department_succeeds(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $regional = Office::factory()->create([
            'name' => 'Regional Supply',
            'is_regional_supply' => true,
        ]);
        $department = Department::query()->create([
            'office_id' => $regional->id,
            'name' => 'Supply Unit',
            'code' => 'SUP',
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->fillForm([
                'first_name' => 'Supply',
                'last_name' => 'Custodian',
                'email' => 'custodian.regional@example.com',
                'role' => User::ROLE_SUPPLY_CUSTODIAN,
                'office_id' => $regional->id,
                'department_id' => $department->id,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $user = User::query()->where('email', 'custodian.regional@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame(User::ROLE_SUPPLY_CUSTODIAN, $user->role);
        $this->assertSame($regional->id, $user->office_id);
        $this->assertSame($department->id, $user->department_id);
    }
}
