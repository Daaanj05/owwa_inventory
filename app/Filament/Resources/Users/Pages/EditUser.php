<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\UserAssignmentActionHooks;
use App\Filament\Support\UserIdentityChangeHooks;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use HasSystemAdminWizardHeading;

    protected static string $resource = UserResource::class;

    /** @var array<int, array{office_id: int, department_id: int}>|null */
    protected ?array $pendingAssignments = null;

    /** @var array<string, mixed> */
    protected array $identityChangeData = [];

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

        if ($record instanceof User) {
            return UserAssignmentActionHooks::fillAssignments($data, $record);
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        if (! $record instanceof User) {
            return $data;
        }

        $data = UserIdentityChangeHooks::prepareSaveData($data, $record);
        $this->identityChangeData = $data;

        if (($data['role'] ?? null) !== User::ROLE_UNIT_CONSOLIDATOR) {
            $this->pendingAssignments = null;

            return $data;
        }

        $data = UserAssignmentActionHooks::prepareSaveData($data, $record);
        $this->pendingAssignments = $data['_assignments'] ?? [];
        unset($data['_assignments']);

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->record instanceof User) {
            UserIdentityChangeHooks::afterSave($this->record, $this->identityChangeData);
            $this->identityChangeData = [];
        }

        if ($this->record->isUnitConsolidator() && $this->pendingAssignments !== null) {
            $this->record->syncOfficeAssignments($this->pendingAssignments);
        }
    }
}
