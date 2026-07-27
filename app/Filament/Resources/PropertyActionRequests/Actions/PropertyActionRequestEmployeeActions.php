<?php

namespace App\Filament\Resources\PropertyActionRequests\Actions;

use App\Filament\Concerns\SwitchesUcSentTab;
use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\PropertyActionRequest;
use App\Models\User;
use App\Services\PropertyActionRequestWorkflowService;
use App\Support\PropertyActionRequestDraftValidator;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class PropertyActionRequestEmployeeActions
{
    public const WORKFLOW_SUBMIT = 'submit';

    public const WORKFLOW_SEND_TO_SC = 'send_to_sc';

    /**
     * @return array<int, Action|ActionGroup>
     */
    public static function tableActionGroup(): ActionGroup
    {
        return ActionGroup::make([
            self::editAction(),
            self::submitAction(),
            self::archiveAction(),
            self::restoreAction(),
        ])
            ->label('Actions')
            ->icon('heroicon-m-ellipsis-vertical')
            ->color('gray')
            ->visible(function (PropertyActionRequest $record): bool {
                if (! (Filament::auth()->user()?->isEmployee() ?? false)) {
                    return false;
                }

                return $record->canEmployeeEdit()
                    || $record->canEmployeeSubmit()
                    || $record->canEmployeeArchive()
                    || $record->canEmployeeRestore();
            });
    }

    /**
     * @return array<int, Action>
     */
    public static function createModalFooterActions(EditAction|Action $parentAction): array
    {
        if (! (Filament::auth()->user()?->isEmployee() ?? false)) {
            return [];
        }

        if (! method_exists($parentAction, 'makeModalSubmitAction')) {
            return [];
        }

        return [
            $parentAction
                ->makeModalSubmitAction('submitToConsolidator', ['workflow' => self::WORKFLOW_SUBMIT])
                ->label('Submit to consolidator')
                ->icon('heroicon-o-paper-airplane')
                ->color('success'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    public static function createUcModalFooterActions(EditAction|Action $parentAction): array
    {
        if (! (Filament::auth()->user()?->isUnitConsolidator() ?? false)) {
            return [];
        }

        if (! method_exists($parentAction, 'makeModalSubmitAction')) {
            return [];
        }

        return [
            $parentAction
                ->makeModalSubmitAction('sendToSupplyCustodian', ['workflow' => self::WORKFLOW_SEND_TO_SC])
                ->label('Send to Supply Custodian')
                ->icon('heroicon-o-paper-airplane')
                ->color('success'),
        ];
    }

    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(PropertyActionRequestResource::class, OwwaFormModalDefaults::WIDTH_MEDIUM)
            ->extraModalWindowAttributes([
                'class' => OwwaFormModalDefaults::MODAL_WINDOW_CLASS.' owwa-property-action-employee-modal',
            ])
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (PropertyActionRequest $record): bool => $record->canEmployeeEdit())
            ->modalSubmitActionLabel('Save draft')
            ->extraModalFooterActions(fn (EditAction $editAction): array => self::createModalFooterActions($editAction))
            ->after(function (PropertyActionRequest $record, EditAction $action): void {
                if (($action->getArguments()['workflow'] ?? null) !== self::WORKFLOW_SUBMIT) {
                    return;
                }

                self::submitRecord($record);
            });
    }

    public static function submitAction(): Action
    {
        return Action::make('submitToConsolidator')
            ->label('Submit to consolidator')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Submit to consolidator?')
            ->modalDescription('Your Unit Consolidator will be notified to review this property return. Reason is required before submitting.')
            ->visible(fn (PropertyActionRequest $record): bool => $record->canEmployeeSubmit())
            ->action(function (PropertyActionRequest $record): void {
                self::submitRecord($record);
            });
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Archive draft?')
            ->modalDescription('This draft will move to the Archived tab. You can restore it later.')
            ->visible(fn (PropertyActionRequest $record): bool => $record->canEmployeeArchive())
            ->action(function (PropertyActionRequest $record): void {
                $record->update(['archived_at' => now()]);

                Notification::make()
                    ->title('Draft archived')
                    ->success()
                    ->send();
            });
    }

    public static function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Restore draft?')
            ->modalDescription('This draft will return to the Active tab.')
            ->visible(fn (PropertyActionRequest $record): bool => $record->canEmployeeRestore())
            ->action(function (PropertyActionRequest $record): void {
                $record->update(['archived_at' => null]);

                Notification::make()
                    ->title('Draft restored')
                    ->success()
                    ->send();
            });
    }

    public static function submitRecord(PropertyActionRequest $record): void
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $record->canEmployeeSubmit()) {
            return;
        }

        PropertyActionRequestDraftValidator::validateRecordReason($record);

        $workflow = app(PropertyActionRequestWorkflowService::class);

        if ($record->status === PropertyActionRequest::STATUS_DRAFT) {
            $workflow->submit($record);
        } else {
            $record->update(['status' => PropertyActionRequest::STATUS_PENDING_UC]);
            $workflow->notifyEmployeeSubmitted($record->fresh());
        }

        Notification::make()
            ->title('Property return submitted')
            ->body('Your Unit Consolidator has been notified.')
            ->success()
            ->send();
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    public static function ucTableActionGroup(): ActionGroup
    {
        return ActionGroup::make([
            self::ucEditAction(),
            self::ucSendToScAction(),
            self::ucArchiveAction(),
            self::ucRestoreAction(),
        ])
            ->label('Actions')
            ->icon('heroicon-m-ellipsis-vertical')
            ->color('gray')
            ->visible(function (PropertyActionRequest $record): bool {
                if (! (Filament::auth()->user()?->isUnitConsolidator() ?? false)) {
                    return false;
                }

                return $record->canUcEdit()
                    || $record->canUcSendToSc()
                    || $record->canUcArchive()
                    || $record->canUcRestore();
            });
    }

    public static function ucEditAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(PropertyActionRequestResource::class, OwwaFormModalDefaults::WIDTH_MEDIUM)
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (PropertyActionRequest $record): bool => $record->canUcEdit())
            ->modalSubmitActionLabel('Save draft')
            ->extraModalFooterActions(fn (EditAction $editAction): array => self::createUcModalFooterActions($editAction))
            ->after(function (PropertyActionRequest $record, EditAction $action): void {
                if (($action->getArguments()['workflow'] ?? null) !== self::WORKFLOW_SEND_TO_SC) {
                    return;
                }

                self::sendToSupplyCustodianRecord($record);
                SwitchesUcSentTab::switchLivewireUcTabToSent($action->getLivewire());
            });
    }

    public static function ucSendToScAction(): Action
    {
        return Action::make('sendToSupplyCustodian')
            ->label('Send to Supply Custodian')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Send to Supply Custodian?')
            ->modalDescription('The Supply Custodian will be notified to review this property return.')
            ->visible(fn (PropertyActionRequest $record): bool => $record->canUcSendToSc())
            ->action(function (PropertyActionRequest $record, Action $action): void {
                self::sendToSupplyCustodianRecord($record);
                SwitchesUcSentTab::switchLivewireUcTabToSent($action->getLivewire());
            });
    }

    public static function ucArchiveAction(): Action
    {
        return Action::make('ucArchive')
            ->label('Archive')
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Archive draft?')
            ->modalDescription('This draft will move to the Archived tab. You can restore it later.')
            ->visible(fn (PropertyActionRequest $record): bool => $record->canUcArchive())
            ->action(function (PropertyActionRequest $record): void {
                $record->update(['archived_at' => now()]);

                Notification::make()
                    ->title('Draft archived')
                    ->success()
                    ->send();
            });
    }

    public static function ucRestoreAction(): Action
    {
        return Action::make('ucRestore')
            ->label('Restore')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Restore draft?')
            ->modalDescription('This draft will return to the Active tab.')
            ->visible(fn (PropertyActionRequest $record): bool => $record->canUcRestore())
            ->action(function (PropertyActionRequest $record): void {
                $record->update(['archived_at' => null]);

                Notification::make()
                    ->title('Draft restored')
                    ->success()
                    ->send();
            });
    }

    public static function sendToSupplyCustodianRecord(PropertyActionRequest $record): void
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->isUnitConsolidator()) {
            return;
        }

        if (! $record->isDraft()) {
            return;
        }

        $record->loadMissing('lines');

        if ($record->lines->isEmpty()) {
            Notification::make()
                ->title('Cannot send to SC')
                ->body('Property return requests require at least one property line.')
                ->danger()
                ->send();

            return;
        }

        PropertyActionRequestDraftValidator::validateRecordReason($record);

        app(PropertyActionRequestWorkflowService::class)->sendToSupplyCustodian($record, $user);

        Notification::make()
            ->title('Property return sent to SC')
            ->body('The Supply Custodian has been notified.')
            ->success()
            ->send();
    }
}
