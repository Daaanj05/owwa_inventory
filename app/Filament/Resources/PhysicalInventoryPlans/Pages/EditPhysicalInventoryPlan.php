<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\Pages;

use App\Filament\Resources\PhysicalInventoryPlans\PhysicalInventoryPlanResource;
use App\Models\PhysicalInventoryPlan;
use App\Services\InventoryPlanValidator;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPhysicalInventoryPlan extends EditRecord
{
    protected static string $resource = PhysicalInventoryPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (PhysicalInventoryPlan $record): bool => $record->isDraft()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $state = $this->form->getState();

        app(InventoryPlanValidator::class)->validateForSave(
            array_merge($this->getRecord()->attributesToArray(), $data),
            $this->getRecord(),
            $state['lines'] ?? [],
        );

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return PhysicalInventoryPlanResource::viewModalUrl($this->getRecord());
    }
}
