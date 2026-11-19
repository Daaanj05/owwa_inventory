<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\Actions;

use App\Filament\Resources\PhysicalInventoryPlans\PhysicalInventoryPlanResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\PhysicalInventoryPlan;
use App\Services\InventoryPlanScheduleSyncService;
use App\Services\InventoryPlanValidator;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class PhysicalInventoryPlanActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(PhysicalInventoryPlanResource::class, OwwaFormModalDefaults::WIDTH_STANDARD)
            ->extraModalWindowAttributes(['class' => OwwaFormModalDefaults::MODAL_WINDOW_CLASS.' owwa-inventory-plan-modal'])
            ->visible(fn (PhysicalInventoryPlan $record): bool => $record->isEditable())
            ->mutateRecordDataUsing(function (array $data, PhysicalInventoryPlan $record): array {
                $data['schedule_slots'] = app(InventoryPlanScheduleSyncService::class)->slotsFromPlan($record);

                return $data;
            })
            ->before(function (EditAction $action, PhysicalInventoryPlan $record): void {
                $data = $action->getFormData();
                $expanded = app(InventoryPlanScheduleSyncService::class)
                    ->expandSlotsToLines($data['schedule_slots'] ?? []);

                app(InventoryPlanValidator::class)->validateForSave(
                    array_merge($record->attributesToArray(), $data),
                    $record,
                    $expanded,
                );
            })
            ->using(function (PhysicalInventoryPlan $record, array $data): PhysicalInventoryPlan {
                $slots = array_values($data['schedule_slots'] ?? []);
                unset($data['schedule_slots'], $data['lines']);

                $record->update($data);
                app(InventoryPlanScheduleSyncService::class)->syncPlanLines($record, $slots);

                return $record->refresh();
            });
    }

    public static function markCompletedAction(): Action
    {
        return Action::make('markPlanCompleted')
            ->label('Mark completed')
            ->icon('heroicon-o-flag')
            ->color('primary')
            ->requiresConfirmation()
            ->visible(fn (PhysicalInventoryPlan $record): bool => $record->isInProgress())
            ->action(function (PhysicalInventoryPlan $record, Action $action): void {
                try {
                    app(InventoryPlanValidator::class)->validateCanComplete($record);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Cannot complete schedule')
                        ->body(collect($exception->errors())->flatten()->first() ?? 'Complete every scheduled count first.')
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                $record->update([
                    'status' => PhysicalInventoryPlan::STATUS_COMPLETED,
                ]);

                Notification::make()
                    ->title('Inventory Schedule completed')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<int, Action|EditAction>
     */
    public static function modalFooterActions(): array
    {
        return [
            self::markCompletedAction(),
            self::editAction(),
        ];
    }
}
