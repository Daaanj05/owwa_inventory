<?php

namespace Tests\Feature;

use App\Filament\Support\UserAssignmentActionHooks;
use App\Models\Department;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UserUnitConsolidatorAssignmentsFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_create_data_accepts_multiple_departments_under_same_office(): void
    {
        [$office, $deptA, $deptB, $deptC] = $this->seedOfficeDepartments();

        $data = UserAssignmentActionHooks::prepareCreateData([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_groups' => [
                [
                    'office_id' => $office->id,
                    'departments' => [
                        ['department_id' => $deptA->id],
                        ['department_id' => $deptB->id],
                        ['department_id' => $deptC->id],
                    ],
                ],
            ],
        ]);

        $this->assertCount(3, $data['_assignments']);
        $this->assertSame($office->id, $data['office_id']);
        $this->assertSame($deptA->id, $data['department_id']);
    }

    public function test_prepare_create_data_accepts_two_offices_with_distinct_departments(): void
    {
        $officeOne = Office::factory()->create(['name' => 'Office One']);
        $officeTwo = Office::factory()->create(['name' => 'Office Two']);
        $deptOne = Department::query()->create(['office_id' => $officeOne->id, 'name' => 'Dept One', 'code' => 'O1']);
        $deptTwo = Department::query()->create(['office_id' => $officeTwo->id, 'name' => 'Dept Two', 'code' => 'O2']);

        $data = UserAssignmentActionHooks::prepareCreateData([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_groups' => [
                [
                    'office_id' => $officeOne->id,
                    'departments' => [
                        ['department_id' => $deptOne->id],
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

        $this->assertCount(2, $data['_assignments']);
    }

    public function test_prepare_create_data_rejects_duplicate_department(): void
    {
        [$office, $deptA] = $this->seedOfficeDepartments();

        $this->expectException(ValidationException::class);

        UserAssignmentActionHooks::prepareCreateData([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_groups' => [
                [
                    'office_id' => $office->id,
                    'departments' => [
                        ['department_id' => $deptA->id],
                        ['department_id' => $deptA->id],
                    ],
                ],
            ],
        ]);
    }

    public function test_prepare_create_data_rejects_duplicate_office_group(): void
    {
        [$office, $deptA, $deptB] = $this->seedOfficeDepartments();

        $this->expectException(ValidationException::class);

        UserAssignmentActionHooks::prepareCreateData([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_groups' => [
                [
                    'office_id' => $office->id,
                    'departments' => [
                        ['department_id' => $deptA->id],
                    ],
                ],
                [
                    'office_id' => $office->id,
                    'departments' => [
                        ['department_id' => $deptB->id],
                    ],
                ],
            ],
        ]);
    }

    public function test_fill_assignments_groups_existing_rows_for_edit(): void
    {
        [$office, $deptA, $deptB] = $this->seedOfficeDepartments();

        $uc = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $deptA->id],
            ['office_id' => $office->id, 'department_id' => $deptB->id],
        ]);

        $data = UserAssignmentActionHooks::fillAssignments([], $uc->fresh());

        $this->assertArrayHasKey('office_groups', $data);
        $this->assertCount(1, $data['office_groups']);
        $this->assertSame($office->id, $data['office_groups'][0]['office_id']);
        $this->assertCount(2, $data['office_groups'][0]['departments']);
        $this->assertSame($deptA->id, $data['office_groups'][0]['departments'][0]);
        $this->assertSame($deptB->id, $data['office_groups'][0]['departments'][1]);
    }

    public function test_group_and_flatten_helpers_round_trip(): void
    {
        $flat = [
            ['office_id' => 1, 'department_id' => 10],
            ['office_id' => 1, 'department_id' => 11],
            ['office_id' => 2, 'department_id' => 20],
        ];

        $grouped = User::groupOfficeAssignmentsForForm($flat);
        $this->assertCount(2, $grouped);
        $this->assertSame(1, $grouped[0]['office_id']);
        $this->assertCount(2, $grouped[0]['departments']);

        $flattened = User::flattenOfficeAssignmentGroups($grouped);
        $this->assertEqualsCanonicalizing($flat, $flattened);
    }

    public function test_flatten_accepts_legacy_flat_rows(): void
    {
        $flat = [
            ['office_id' => 1, 'department_id' => 10],
            ['office_id' => 1, 'department_id' => 11],
        ];

        $this->assertEqualsCanonicalizing($flat, User::flattenOfficeAssignmentGroups($flat));
    }

    public function test_sync_after_create_persists_nested_assignments(): void
    {
        [$office, $deptA, $deptB, $deptC] = $this->seedOfficeDepartments();

        $user = User::factory()->create(['role' => User::ROLE_UNIT_CONSOLIDATOR]);
        $assignments = User::flattenOfficeAssignmentGroups([
            [
                'office_id' => $office->id,
                'departments' => [
                    ['department_id' => $deptA->id],
                    ['department_id' => $deptB->id],
                    ['department_id' => $deptC->id],
                ],
            ],
        ]);

        UserAssignmentActionHooks::syncAfterSave($user, ['_assignments' => $assignments]);

        $user->refresh();
        $this->assertCount(3, $user->assignments);
    }

    /**
     * @return array{0: Office, 1: Department, 2: Department, 3: Department}
     */
    protected function seedOfficeDepartments(): array
    {
        $office = Office::factory()->create();
        $deptA = Department::query()->create(['office_id' => $office->id, 'name' => 'Dept A', 'code' => 'A']);
        $deptB = Department::query()->create(['office_id' => $office->id, 'name' => 'Dept B', 'code' => 'B']);
        $deptC = Department::query()->create(['office_id' => $office->id, 'name' => 'Dept C', 'code' => 'C']);

        return [$office, $deptA, $deptB, $deptC];
    }
}
