<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateUser extends CreateRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = UserResource::class;

    /** @var array<int, array{office_id: int, department_id: int}> */
    protected array $pendingAssignments = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['role'] ?? null) === User::ROLE_UNIT_CONSOLIDATOR) {
            $this->pendingAssignments = User::normalizeAssignmentRows($data['assignments'] ?? []);
            unset($data['assignments'], $data['office_id'], $data['department_id']);

            if ($this->pendingAssignments === []) {
                throw ValidationException::withMessages([
                    'assignments' => 'Add at least one office and sub-office/department for a Unit Consolidator.',
                ]);
            }

            $first = $this->pendingAssignments[0];
            $data['office_id'] = $first['office_id'];
            $data['department_id'] = $first['department_id'];
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->record->isUnitConsolidator() && $this->pendingAssignments !== []) {
            $this->record->syncOfficeAssignments($this->pendingAssignments);
        }
    }
}
