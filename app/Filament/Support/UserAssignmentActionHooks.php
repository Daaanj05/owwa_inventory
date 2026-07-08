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

        $assignments = User::normalizeAssignmentRows($data['assignments'] ?? []);
        unset($data['assignments']);

        if ($assignments === []) {
            throw ValidationException::withMessages([
                'assignments' => 'Add at least one office and sub-office/department for a Unit Consolidator.',
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

        $data['assignments'] = $record->assignments()
            ->orderBy('id')
            ->get()
            ->map(fn ($assignment): array => [
                'office_id' => $assignment->office_id,
                'department_id' => $assignment->department_id,
            ])
            ->all();

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

        $assignments = User::normalizeAssignmentRows($data['assignments'] ?? []);
        unset($data['assignments']);

        if ($assignments === []) {
            throw ValidationException::withMessages([
                'assignments' => 'Add at least one office and sub-office/department for a Unit Consolidator.',
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
}
