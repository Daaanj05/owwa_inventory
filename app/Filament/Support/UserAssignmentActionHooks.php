<?php

namespace App\Filament\Support;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class UserAssignmentActionHooks
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareCreateData(array $data): array
    {
        if (($data['role'] ?? null) !== User::ROLE_UNIT_CONSOLIDATOR) {
            return $data;
        }

        $groups = $data['office_groups'] ?? [];
        self::assertUniqueOfficeGroups($groups);

        $assignments = User::flattenOfficeAssignmentGroups($groups);
        unset($data['office_groups']);

        if ($assignments === []) {
            throw ValidationException::withMessages([
                'office_groups' => 'Add at least one office and sub-office/department for a Unit Consolidator.',
            ]);
        }

        $data['_assignments'] = $assignments;
        $first = $assignments[0];
        $data['office_id'] = $first['office_id'];
        $data['department_id'] = $first['department_id'];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillAssignments(array $data, User $record): array
    {
        if (! $record->isUnitConsolidator()) {
            return $data;
        }

        $data['office_groups'] = User::groupOfficeAssignmentsForForm(
            $record->assignments()
                ->orderBy('id')
                ->get()
                ->map(fn ($assignment): array => [
                    'office_id' => $assignment->office_id,
                    'department_id' => $assignment->department_id,
                ])
                ->all(),
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareSaveData(array $data, User $record): array
    {
        if (($data['role'] ?? null) !== User::ROLE_UNIT_CONSOLIDATOR) {
            return $data;
        }

        $groups = $data['office_groups'] ?? [];
        self::assertUniqueOfficeGroups($groups);

        $assignments = User::flattenOfficeAssignmentGroups($groups);
        unset($data['office_groups']);

        if ($assignments === []) {
            throw ValidationException::withMessages([
                'office_groups' => 'Add at least one office and sub-office/department for a Unit Consolidator.',
            ]);
        }

        $data['_assignments'] = $assignments;
        $first = $assignments[0];
        $data['office_id'] = $first['office_id'];
        $data['department_id'] = $first['department_id'];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function syncAfterSave(User $record, array $data): void
    {
        if (! $record->isUnitConsolidator()) {
            return;
        }

        $assignments = $data['_assignments'] ?? [];

        if ($assignments === []) {
            return;
        }

        $record->syncOfficeAssignments($assignments);
    }

    /**
     * @param  array<int, array{office_id?: mixed, department_id?: mixed, departments?: array<int, array{department_id?: mixed}>}>|array<int, array{office_id: int, department_id: int}>  $groups
     */
    protected static function assertUniqueOfficeGroups(array $groups): void
    {
        if ($groups === []) {
            return;
        }

        if (isset($groups[0]['department_id'])) {
            $officeIds = collect($groups)
                ->pluck('office_id')
                ->filter()
                ->map(fn (mixed $id): int => (int) $id);

            if ($officeIds->count() !== $officeIds->unique()->count()) {
                throw ValidationException::withMessages([
                    'office_groups' => 'Each office may only be added once. Add more departments under the existing office entry.',
                ]);
            }

            $departmentIds = collect($groups)
                ->pluck('department_id')
                ->filter()
                ->map(fn (mixed $id): int => (int) $id);

            if ($departmentIds->count() !== $departmentIds->unique()->count()) {
                throw ValidationException::withMessages([
                    'office_groups' => 'Each sub-office/department may only be assigned once.',
                ]);
            }

            return;
        }

        $officeIds = collect($groups)
            ->pluck('office_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id);

        if ($officeIds->count() !== $officeIds->unique()->count()) {
            throw ValidationException::withMessages([
                'office_groups' => 'Each office may only be added once. Add more departments under the existing office entry.',
            ]);
        }

        $departmentIds = collect($groups)
            ->flatMap(function (array $group): array {
                $departments = is_array($group['departments'] ?? null) ? $group['departments'] : [];

                return collect($departments)
                    ->pluck('department_id')
                    ->filter()
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();
            });

        if ($departmentIds->count() !== $departmentIds->unique()->count()) {
            throw ValidationException::withMessages([
                'office_groups' => 'Each sub-office/department may only be assigned once.',
            ]);
        }
    }
}
