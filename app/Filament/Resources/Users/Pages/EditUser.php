<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = UserResource::class;

    /** @var array<int, array{office_id: int, department_id: int}>|null */
    protected ?array $pendingAssignments = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (User $record): bool => $record->id === auth()->id()),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if ($record instanceof User && $record->isUnitConsolidator()) {
            $data['assignments'] = $record->assignments()
                ->orderBy('id')
                ->get()
                ->map(fn ($assignment): array => [
                    'office_id' => $assignment->office_id,
                    'department_id' => $assignment->department_id,
                ])
                ->all();
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['role'] ?? null) === User::ROLE_UNIT_CONSOLIDATOR) {
            $this->pendingAssignments = User::normalizeAssignmentRows($data['assignments'] ?? []);
            unset($data['assignments']);

            if ($this->pendingAssignments === []) {
                throw ValidationException::withMessages([
                    'assignments' => 'Add at least one office and sub-office/department for a Unit Consolidator.',
                ]);
            }

            $first = $this->pendingAssignments[0];
            $data['office_id'] = $first['office_id'];
            $data['department_id'] = $first['department_id'];
        } else {
            $this->pendingAssignments = null;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->record->isUnitConsolidator() && $this->pendingAssignments !== null) {
            $this->record->syncOfficeAssignments($this->pendingAssignments);
        }
    }
}
