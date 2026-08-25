<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Department;
use App\Models\Office;
use App\Models\User;
use App\Models\UserOfficeAssignment;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class UserUnitConsolidatorCreateModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_can_create_unit_consolidator_with_office_and_department(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $office = Office::factory()->create(['name' => 'OWWA REGION IV-A']);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'ADMIN - HR/GAS',
            'code' => 'HRGAS',
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        $component = Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->fillForm([
                'first_name' => 'Test',
                'last_name' => 'Consolidator',
                'middle_name' => 'UC',
                'email' => 'uc.create@example.com',
                'role' => User::ROLE_UNIT_CONSOLIDATOR,
            ]);

        $this->fillMountedOfficeGroups($component, [
            [
                'office_id' => $office->id,
                'departments' => [$department->id],
            ],
        ]);

        $component
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $user = User::query()->where('email', 'uc.create@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame(User::ROLE_UNIT_CONSOLIDATOR, $user->role);
        $this->assertSame($office->id, $user->office_id);
        $this->assertSame($department->id, $user->department_id);
        $this->assertDatabaseHas(UserOfficeAssignment::class, [
            'user_id' => $user->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
    }

    public function test_system_admin_can_create_unit_consolidator_with_multiple_departments(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $office = Office::factory()->create(['name' => 'Office Multi']);
        $deptA = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Dept A',
            'code' => 'A1',
        ]);
        $deptB = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Dept B',
            'code' => 'A2',
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin);

        $component = Livewire::test(ListUsers::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->fillForm([
                'first_name' => 'Multi',
                'last_name' => 'Dept',
                'email' => 'uc.multi@example.com',
                'role' => User::ROLE_UNIT_CONSOLIDATOR,
            ]);

        $this->fillMountedOfficeGroups($component, [
            [
                'office_id' => $office->id,
                'departments' => [$deptA->id, $deptB->id],
            ],
        ]);

        $component
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $user = User::query()->where('email', 'uc.multi@example.com')->first();
        $this->assertNotNull($user);
        $this->assertCount(2, $user->assignments);
    }

    /**
     * @param  array<int, array{office_id: int, departments: array<int, int>}>  $groups
     */
    protected function fillMountedOfficeGroups(Testable $component, array $groups): void
    {
        $existing = data_get($component->get('mountedActions'), '0.data.office_groups', []);
        $keys = array_keys(is_array($existing) ? $existing : []);

        $payload = [];

        foreach ($groups as $index => $group) {
            $key = $keys[$index] ?? (string) str()->uuid();
            $payload[$key] = $group;
        }

        $component->fillForm([
            'office_groups' => $payload,
        ]);
    }
}
