<?php

namespace App\Filament\Resources\Requisitions\Actions;

use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Requisition;
use App\Models\User;
use App\Services\RequisitionStockSnapshotService;
use App\Services\RequisitionWorkflowNotificationService;
use App\Support\EmployeeRequisitionDraftValidator;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class EmployeeRequisitionActions
{
    public const WORKFLOW_SUBMIT = 'submit';

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
            ->visible(function (Requisition $record): bool {
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

    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editActionForResource(RequisitionResource::class, OwwaFormModalDefaults::WIDTH_MEDIUM)
            ->extraModalWindowAttributes([
                'class' => OwwaFormModalDefaults::MODAL_WINDOW_CLASS.' owwa-requisition-employee-modal',
            ])
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (Requisition $record): bool => $record->canEmployeeEdit())
            ->modalSubmitActionLabel(fn (Requisition $record): string => $record->isDraft()
                ? 'Save draft'
                : 'Save changes')
            ->extraModalFooterActions(function (EditAction $editAction): array {
                $record = $editAction->getRecord();

                if (! $record instanceof Requisition || ! $record->isDraft()) {
                    return [];
                }

                return self::createModalFooterActions($editAction);
            })
            ->after(function (Requisition $record, EditAction $action): void {
                if (($action->getArguments()['workflow'] ?? null) === self::WORKFLOW_SUBMIT) {
                    self::submitRecord($record);

                    return;
                }

                if ($record->isPendingCustodianReview()) {
                    self::handlePendingContentSaved($record);
                }
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
            ->modalDescription('Your Unit Consolidator will be notified to review this requisition. Purpose is required before submitting.')
            ->visible(fn (Requisition $record): bool => $record->canEmployeeSubmit())
            ->action(function (Requisition $record): void {
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
            ->visible(fn (Requisition $record): bool => $record->canEmployeeArchive())
            ->action(function (Requisition $record): void {
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
            ->visible(fn (Requisition $record): bool => $record->canEmployeeRestore())
            ->action(function (Requisition $record): void {
                $record->update(['archived_at' => null]);

                Notification::make()
                    ->title('Draft restored')
                    ->success()
                    ->send();
            });
    }

    public static function submitRecord(Requisition $record): void
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $record->canEmployeeSubmit()) {
            return;
        }

        EmployeeRequisitionDraftValidator::validateRecordPurpose($record);

        app(RequisitionStockSnapshotService::class)->snapshotRequisitionLines($record);

        $record->update(['status' => Requisition::STATUS_PENDING]);
        $record->refresh();
        $record->snapshotOriginalSubmissionIfNeeded();

        Notification::make()
            ->title('Requisition submitted')
            ->body('Your Unit Consolidator has been notified.')
            ->success()
            ->send();
    }

    public static function handlePendingContentSaved(Requisition $record): void
    {
        $record->refresh();
        $record->load('items');

        EmployeeRequisitionDraftValidator::validateRecordPurpose($record);

        $wasEdited = $record->hasEmployeeContentEdits();
        $previouslyMarked = $record->content_edited_at !== null;

        $record->forceFill([
            'content_edited_at' => $wasEdited ? now() : null,
        ])->saveQuietly();

        if ($wasEdited) {
            app(RequisitionWorkflowNotificationService::class)->handleEmployeeContentEdited($record);

            Notification::make()
                ->title('Changes saved')
                ->body($previouslyMarked
                    ? 'Your Unit Consolidator will see the updated request.'
                    : 'Your Unit Consolidator has been notified of your changes.')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Changes saved')
            ->success()
            ->send();
    }
}
