<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\UserAssignmentActionHooks;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = UserResource::class;

    /** @var array<int, array{office_id: int, department_id: int}> */
    protected array $pendingAssignments = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['role'] ?? null) !== User::ROLE_UNIT_CONSOLIDATOR) {
            return $data;
        }

        $data = UserAssignmentActionHooks::prepareCreateData($data);
        $this->pendingAssignments = $data['_assignments'] ?? [];
        unset($data['_assignments']);

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->record->isUnitConsolidator() && $this->pendingAssignments !== []) {
            $this->record->syncOfficeAssignments($this->pendingAssignments);
        }
    }
}
