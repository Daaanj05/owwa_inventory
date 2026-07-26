<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Support\UserAssignmentActionHooks;
use App\Models\Department;
use App\Models\Office;
use App\Models\User;
use App\Models\UserOfficeAssignment;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserUnitConsolidatorLivewireCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_users_create_path_syncs_multiple_offices_and_departments(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('system-admin'));

        $officeOne = Office::factory()->create(['name' => 'Office Alpha']);
        $officeTwo = Office::factory()->create(['name' => 'Office Beta']);
        $deptOneA = Department::query()->create(['office_id' => $officeOne->id, 'name' => 'Dept A', 'code' => 'A1']);
        $deptOneB = Department::query()->create(['office_id' => $officeOne->id, 'name' => 'Dept B', 'code' => 'A2']);
        $deptTwo = Department::query()->create(['office_id' => $officeTwo->id, 'name' => 'Dept C', 'code' => 'B1']);

        $admin = User::factory()->create([
            'role' => User::ROLE_SYSTEM_ADMIN,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($admin);

        // Mirrors ListUsers create mutateDataUsing → pendingAssignments → after sync.
        $prepared = UserAssignmentActionHooks::prepareCreateData([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_groups' => [
                [
                    'office_id' => $officeOne->id,
                    'departments' => [
                        ['department_id' => $deptOneA->id],
                        ['department_id' => $deptOneB->id],
                    ],
                ],
                [
                    'office_id' => $officeTwo->id,
                    'departments' => [
                        ['department_id' => $deptTwo->id],
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create([
            'first_name' => 'Multi',
            'last_name' => 'Consolidator',
            'email' => 'multi.uc@example.com',
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $prepared['office_id'],
            'department_id' => $prepared['department_id'],
            'email_verified_at' => null,
            'must_change_password' => true,
        ]);

        $user->syncOfficeAssignments($prepared['_assignments']);

        $assignmentPairs = UserOfficeAssignment::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->map(fn (UserOfficeAssignment $row): array => [
                'office_id' => $row->office_id,
                'department_id' => $row->department_id,
            ])
            ->all();

        $this->assertCount(3, $assignmentPairs);
        $this->assertEqualsCanonicalizing([
            ['office_id' => $officeOne->id, 'department_id' => $deptOneA->id],
            ['office_id' => $officeOne->id, 'department_id' => $deptOneB->id],
            ['office_id' => $officeTwo->id, 'department_id' => $deptTwo->id],
        ], $assignmentPairs);

        $this->assertSame('Office Alpha (+1)', $user->fresh()->load(['assignments.office', 'office'])->assignmentOfficesSummary());
        $this->assertSame('Dept A (+2)', $user->fresh()->load(['assignments.department', 'department'])->assignmentDepartmentsSummary());

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$user])
            ->assertTableColumnStateSet('office.name', 'Office Alpha (+1)', $user);

        $filled = UserAssignmentActionHooks::fillAssignments([], $user->fresh());
        $this->assertCount(2, $filled['office_groups']);
        $this->assertSame($officeOne->id, $filled['office_groups'][0]['office_id']);
        $this->assertCount(2, $filled['office_groups'][0]['departments']);
        $this->assertSame($officeTwo->id, $filled['office_groups'][1]['office_id']);
        $this->assertCount(1, $filled['office_groups'][1]['departments']);
    }

    public function test_flatten_accepts_simple_repeater_scalar_department_ids(): void
    {
        $office = Office::factory()->create();
        $deptA = Department::query()->create(['office_id' => $office->id, 'name' => 'Dept A', 'code' => 'A1']);
        $deptB = Department::query()->create(['office_id' => $office->id, 'name' => 'Dept B', 'code' => 'A2']);

        $rows = User::flattenOfficeAssignmentGroups([
            [
                'office_id' => $office->id,
                'departments' => [$deptA->id, $deptB->id],
            ],
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame($deptA->id, $rows[0]['department_id']);
        $this->assertSame($deptB->id, $rows[1]['department_id']);
    }
}
