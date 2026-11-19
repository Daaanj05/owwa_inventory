<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\Pages;

use App\Filament\Resources\PhysicalInventoryPlans\PhysicalInventoryPlanResource;
use App\Models\PhysicalInventoryPlan;
use App\Services\InventoryPlanScheduleSyncService;
use App\Services\InventoryPlanValidator;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPhysicalInventoryPlan extends EditRecord
{
    protected static string $resource = PhysicalInventoryPlanResource::class;

    /** @var array<int, array<string, mixed>> */
    protected array $pendingScheduleSlots = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (PhysicalInventoryPlan $record): bool => $record->isEditable()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['schedule_slots'] = app(InventoryPlanScheduleSyncService::class)
            ->slotsFromPlan($this->getRecord());

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingScheduleSlots = array_values($data['schedule_slots'] ?? []);
        $expanded = app(InventoryPlanScheduleSyncService::class)
            ->expandSlotsToLines($this->pendingScheduleSlots);

        app(InventoryPlanValidator::class)->validateForSave(
            array_merge($this->getRecord()->attributesToArray(), $data),
            $this->getRecord(),
            $expanded,
        );

        unset($data['schedule_slots'], $data['lines']);

        return $data;
    }

    protected function afterSave(): void
    {
        app(InventoryPlanScheduleSyncService::class)
            ->syncPlanLines($this->getRecord(), $this->pendingScheduleSlots);
    }

    protected function getRedirectUrl(): string
    {
        return PhysicalInventoryPlanResource::viewModalUrl($this->getRecord());
    }
}
