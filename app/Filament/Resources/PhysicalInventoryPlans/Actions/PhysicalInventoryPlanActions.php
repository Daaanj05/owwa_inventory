<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\Actions;

use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\PhysicalInventoryPlan;
use App\Services\InventoryPlanValidator;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class PhysicalInventoryPlanActions
{
    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_STANDARD)
            ->extraModalWindowAttributes(['class' => OwwaFormModalDefaults::MODAL_WINDOW_CLASS.' owwa-inventory-plan-modal'])
            ->visible(fn (PhysicalInventoryPlan $record): bool => ! $record->isCompleted())
            ->before(function (EditAction $action, PhysicalInventoryPlan $record): void {
                $data = $action->getFormData();

                app(InventoryPlanValidator::class)->validateForSave(
                    array_merge($record->attributesToArray(), $data),
                    $record,
                    $data['lines'] ?? [],
                );
            });
    }

    public static function approveAction(): Action
    {
        return Action::make('approvePlan')
            ->label('Approve schedule')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (PhysicalInventoryPlan $record): bool => $record->isDraft())
            ->action(function (PhysicalInventoryPlan $record, Action $action): void {
                $record->update([
                    'status' => PhysicalInventoryPlan::STATUS_APPROVED,
                    'approved_at' => now(),
                ]);

                Notification::make()
                    ->title('Inventory Schedule approved')
                    ->success()
                    ->send();

                $action->halt();
            });
    }

    public static function markCompletedAction(): Action
    {
        return Action::make('markPlanCompleted')
            ->label('Mark completed')
            ->icon('heroicon-o-flag')
            ->color('primary')
            ->requiresConfirmation()
            ->visible(fn (PhysicalInventoryPlan $record): bool => ! $record->isCompleted() && ! $record->isDraft())
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

                $action->halt();
            });
    }

    /**
     * @return array<int, Action|EditAction>
     */
    public static function modalFooterActions(): array
    {
        return [
            self::approveAction(),
            self::markCompletedAction(),
            self::editAction(),
        ];
    }
}
