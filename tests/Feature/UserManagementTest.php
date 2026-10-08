<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Department;
use App\Models\Office;
use App\Models\User;
use App\Support\FriendlyMessages;
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
            ->assertTableColumnExists('name')
            ->assertTableColumnExists('email')
            ->assertTableColumnExists('email_verified_at')
            ->assertTableColumnExists('role')
            ->assertTableColumnExists('office.name')
            ->assertTableColumnDoesNotExist('department.name')
            ->assertTableColumnDoesNotExist('pendingPasswordResetRequest.requested_at')
            ->assertTableColumnStateSet('email_verified_at', 'Verified', $verifiedEmployee)
            ->assertTableColumnStateSet('email_verified_at', 'Pending', $pendingEmployee);
    }

    public function test_view_user_modal_shows_status_details_without_repeating_identity(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $office = Office::factory()->create(['name' => 'Regional Office IV-A']);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Finance Division',
            'code' => 'FIN',
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'first_name' => 'Anna',
            'last_name' => 'Reyes',
            'email' => 'anna@owwa.gov.ph',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        $hero = \App\Support\OwwaTransactionViewPresenter::forUser($employee->fresh(['office', 'department']));
        $this->assertSame('anna@owwa.gov.ph', collect($hero['meta'] ?? [])->firstWhere('label', 'Email')['value'] ?? null);
        $this->assertSame('Verified', collect($hero['meta'] ?? [])->firstWhere('label', 'Verification')['value'] ?? null);
        $this->assertSame('Regional Office IV-A', collect($hero['meta'] ?? [])->firstWhere('label', 'Office')['value'] ?? null);
        $this->assertNull(collect($hero['meta'] ?? [])->firstWhere('label', 'Department'));

        $detailKeys = collect(\App\Filament\Resources\Users\Schemas\UserInfolist::modalDetailSections())
            ->map(fn ($component) => method_exists($component, 'getName') ? $component->getName() : null)
            ->filter()
            ->values()
            ->all();

        $this->assertContains('email_verified_at', $detailKeys);
        $this->assertContains('department.name', $detailKeys);
        $this->assertNotContains('name', $detailKeys);
        $this->assertNotContains('email', $detailKeys);
        $this->assertNotContains('role', $detailKeys);
        $this->assertNotContains('office.name', $detailKeys);
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
            ->assertFormFieldExists('role')
            ->assertFormFieldDoesNotExist('password');
    }

    public function test_admin_email_helper_explains_verification_and_session_revoke(): void
    {
        $message = FriendlyMessages::adminEmailEditHelper();

        $this->assertStringContainsString('sign-in email', $message);
        $this->assertStringContainsString('verified before login', $message);
        $this->assertStringContainsString('signs the person out of existing sessions', $message);
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
            ->assertFormFieldExists('office_id')
            ->assertFormFieldDoesNotExist('password');
    }

    public function test_admin_can_update_user_name_without_clearing_verification(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations',
            'code' => 'OPS',
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'first_name' => 'Jane',
            'last_name' => 'Public',
            'email' => 'jane.public@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('edit')->table($employee))
            ->fillForm([
                'first_name' => 'Janet',
                'last_name' => 'Citizen',
                'email' => 'jane.public@example.com',
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $office->id,
                'department_id' => $department->id,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $employee->refresh();

        $this->assertSame('Janet', $employee->first_name);
        $this->assertSame('Citizen', $employee->last_name);
        $this->assertSame('jane.public@example.com', $employee->email);
        $this->assertNotNull($employee->email_verified_at);
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
            ->mountAction(TestAction::make('create'))
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
            ->mountAction(TestAction::make('create'))
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
            ->mountAction(TestAction::make('create'))
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
