<?php

namespace App\Filament\Resources\Acquisitions\Paperwork\Actions;

use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Acquisitions\Paperwork\Schemas\AcquisitionPaperworkInfolist;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\AcquisitionPaperwork;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Services\InventoryQrLabelService;
use App\Support\AcquisitionPaperworkViewPresenter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;

class AcquisitionPaperworkActions
{
    public static function configureEditAction(): EditAction
    {
        return OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_WIDE)
            ->label('')
            ->tableIcon(null)
            ->extraAttributes(['class' => 'sr-only'])
            ->visible(fn (AcquisitionPaperwork $record): bool => ! $record->isReceived())
            ->extraModalWindowAttributes(['class' => 'owwa-view-record-modal owwa-record-modal owwa-acquisition-paperwork-modal'])
            ->modalHeading(fn (AcquisitionPaperwork $record): string => AcquisitionPaperworkViewPresenter::editModalHeading($record))
            ->modalSubmitActionLabel('Save draft')
            ->modalSubmitAction(fn (Action $action): Action => $action->visible(
                fn (?AcquisitionPaperwork $record): bool => $record !== null
                    && ! AcquisitionPaperworkViewPresenter::isCurrentPhasePending($record),
            ))
            ->after(function (AcquisitionPaperwork $record, EditAction $action): void {
                $workflow = $action->getArguments()['workflow'] ?? null;

                if (! is_string($workflow) || $workflow === '') {
                    return;
                }

                $config = match ($workflow) {
                    'submitPr' => [
                        'handler' => fn (AcquisitionPaperwork $paperwork) => app(AcquisitionPaperworkCompletionService::class)->submitPr($paperwork),
                        'successTitle' => 'PR submitted',
                        'successBody' => 'Export the purchase request and route for offline approval.',
                        'phase' => AcquisitionPaperwork::PHASE_PR,
                    ],
                    'submitPo' => [
                        'handler' => fn (AcquisitionPaperwork $paperwork) => app(AcquisitionPaperworkCompletionService::class)->submitPo($paperwork),
                        'successTitle' => 'PO submitted',
                        'successBody' => 'Export the purchase order for the supplier.',
                        'phase' => AcquisitionPaperwork::PHASE_PO,
                    ],
                    'submitIar' => [
                        'handler' => fn (AcquisitionPaperwork $paperwork) => app(AcquisitionPaperworkCompletionService::class)->submitIar($paperwork),
                        'successTitle' => 'IAR submitted',
                        'successBody' => 'Export the inspection report and file with records.',
                        'phase' => AcquisitionPaperwork::PHASE_IAR,
                    ],
                    default => null,
                };

                if ($config === null) {
                    return;
                }

                self::runWorkflowHandler(
                    $record->fresh(),
                    $config['handler'],
                    $action,
                    $config['successTitle'],
                    $config['successBody'],
                    $config['phase'],
                );
            })
            ->registerModalActions(self::hiddenPhaseViewActionsForStepper())
            ->extraModalFooterActions(fn (EditAction $editAction): array => self::editModalFooterActions($editAction));
    }

    public static function submitPrAction(bool $fromEditModal = false): Action
    {
        return self::workflowAction(
            name: 'submitPr',
            label: $fromEditModal ? 'Save & submit PR for export' : 'Save & submit PR for export',
            description: 'Saves your entries, locks PR fields, and prepares the form for offline export.',
            visible: fn (AcquisitionPaperwork $record): bool => ! $record->isPrApproved()
                && $record->pr_status === AcquisitionPaperwork::STATUS_DRAFT,
            handler: fn (AcquisitionPaperwork $record) => app(AcquisitionPaperworkCompletionService::class)->submitPr($record),
            successTitle: 'PR submitted',
            successBody: 'Export the purchase request and route for offline approval.',
            phase: AcquisitionPaperwork::PHASE_PR,
            fromEditModal: $fromEditModal,
        );
    }

    public static function approvePrAction(): Action
    {
        return self::workflowAction(
            name: 'approvePr',
            label: 'Record offline approval',
            description: 'Assigns PR No. and unlocks PO after offline approval is recorded.',
            visible: fn (AcquisitionPaperwork $record): bool => $record->pr_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            handler: fn (AcquisitionPaperwork $record) => app(AcquisitionPaperworkCompletionService::class)->approvePr($record),
            successTitle: 'PR approved',
            successBody: 'PO phase is now unlocked.',
            phase: AcquisitionPaperwork::PHASE_PR,
        );
    }

    public static function submitPoAction(bool $fromEditModal = false): Action
    {
        return self::workflowAction(
            name: 'submitPo',
            label: 'Save & submit PO for export',
            description: 'Saves your entries, locks PO fields, and prepares the form for offline export.',
            visible: fn (AcquisitionPaperwork $record): bool => $record->isPrApproved()
                && ! $record->isPoApproved()
                && $record->po_status === AcquisitionPaperwork::STATUS_DRAFT,
            handler: fn (AcquisitionPaperwork $record) => app(AcquisitionPaperworkCompletionService::class)->submitPo($record),
            successTitle: 'PO submitted',
            successBody: 'Export the purchase order for the supplier.',
            phase: AcquisitionPaperwork::PHASE_PO,
            fromEditModal: $fromEditModal,
        );
    }

    public static function approvePoAction(): Action
    {
        return self::workflowAction(
            name: 'approvePo',
            label: 'Record offline approval',
            description: 'Assigns PO No. and unlocks IAR.',
            visible: fn (AcquisitionPaperwork $record): bool => $record->po_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            handler: fn (AcquisitionPaperwork $record) => app(AcquisitionPaperworkCompletionService::class)->approvePo($record),
            successTitle: 'PO approved',
            successBody: 'IAR phase is now unlocked.',
            phase: AcquisitionPaperwork::PHASE_PO,
        );
    }

    public static function submitIarAction(bool $fromEditModal = false): Action
    {
        return self::workflowAction(
            name: 'submitIar',
            label: 'Save & submit IAR for export',
            description: 'Saves your entries, locks IAR fields, and prepares the form for offline export.',
            visible: fn (AcquisitionPaperwork $record): bool => $record->isPoApproved()
                && ! $record->isIarApproved()
                && $record->iar_status === AcquisitionPaperwork::STATUS_DRAFT,
            handler: fn (AcquisitionPaperwork $record) => app(AcquisitionPaperworkCompletionService::class)->submitIar($record),
            successTitle: 'IAR submitted',
            successBody: 'Export the inspection report and file with records.',
            phase: AcquisitionPaperwork::PHASE_IAR,
            fromEditModal: $fromEditModal,
        );
    }

    public static function approveIarAction(): Action
    {
        return self::workflowAction(
            name: 'approveIar',
            label: 'Record offline approval',
            description: 'Assigns IAR No. You can then record custodian receipt when goods arrive.',
            visible: fn (AcquisitionPaperwork $record): bool => $record->iar_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            handler: fn (AcquisitionPaperwork $record) => app(AcquisitionPaperworkCompletionService::class)->approveIar($record),
            successTitle: 'IAR approved',
            successBody: 'Record custodian receipt when stock is received.',
            phase: AcquisitionPaperwork::PHASE_IAR,
        );
    }

    public static function recordCustodyReceiptAction(): Action
    {
        return Action::make('recordCustodyReceipt')
            ->label('Record custodian receipt')
            ->icon('heroicon-o-check')
            ->color('primary')
            ->visible(fn (AcquisitionPaperwork $record): bool => $record->isIarApproved() && ! $record->isReceived())
            ->requiresConfirmation()
            ->modalDescription('Creates one custodian receipt per line and updates stock levels.')
            ->action(function (AcquisitionPaperwork $record, Action $action): void {
                self::runWorkflowHandler(
                    $record,
                    fn (AcquisitionPaperwork $paperwork) => app(AcquisitionPaperworkCompletionService::class)->recordCustodyReceipts($paperwork),
                    $action,
                    'Custodian receipts recorded',
                    'Stock levels updated. Status is now Received.',
                );

                $record->refresh();

                if ($record->isReceived()) {
                    $action->redirect(AcquisitionResource::viewModalUrl($record));
                }
            });
    }

    public static function printUnitQrLabelsAction(): Action
    {
        return Action::make('printUnitQrLabels')
            ->label('Print unit QR labels')
            ->icon('heroicon-o-qr-code')
            ->color('primary')
            ->visible(fn (AcquisitionPaperwork $record): bool => self::supportsQrLabels($record))
            ->url(fn (AcquisitionPaperwork $record): string => route('owwa.qr-labels.acquisition-paperwork', $record))
            ->openUrlInNewTab();
    }

    public static function supportsQrLabels(AcquisitionPaperwork $record): bool
    {
        return app(InventoryQrLabelService::class)->supportsPaperworkQrLabels($record);
    }

    public static function viewPrAction(): Action
    {
        return self::phaseViewAction('viewPr', 'Purchase request', AcquisitionPaperworkInfolist::prSection(), [
            self::exportPrAction(),
            self::approvePrAction(),
        ]);
    }

    public static function viewPoAction(): Action
    {
        return self::phaseViewAction('viewPo', 'Purchase order', AcquisitionPaperworkInfolist::poSection(), [
            self::exportPoAction(),
            self::approvePoAction(),
        ]);
    }

    public static function viewIarAction(): Action
    {
        return self::phaseViewAction('viewIar', 'Inspection & acceptance', AcquisitionPaperworkInfolist::iarSection(), [
            self::exportIarAction(),
            self::approveIarAction(),
        ]);
    }

    public static function phaseViewsActionGroup(): ActionGroup
    {
        return ActionGroup::make([
            self::viewPrAction()
                ->visible(fn (AcquisitionPaperwork $record): bool => self::isPhaseViewable($record, AcquisitionPaperwork::PHASE_PR)),
            self::viewPoAction()
                ->visible(fn (AcquisitionPaperwork $record): bool => self::isPhaseViewable($record, AcquisitionPaperwork::PHASE_PO)),
            self::viewIarAction()
                ->visible(fn (AcquisitionPaperwork $record): bool => self::isPhaseViewable($record, AcquisitionPaperwork::PHASE_IAR)),
        ])
            ->label('')
            ->tooltip('View forms')
            ->icon('heroicon-m-ellipsis-vertical')
            ->color('gray')
            ->button()
            ->visible(fn (AcquisitionPaperwork $record): bool => self::isPhaseViewable($record, AcquisitionPaperwork::PHASE_PR)
                || self::isPhaseViewable($record, AcquisitionPaperwork::PHASE_PO)
                || self::isPhaseViewable($record, AcquisitionPaperwork::PHASE_IAR));
    }

    protected static function isPhaseViewable(AcquisitionPaperwork $record, string $phase): bool
    {
        return match ($phase) {
            AcquisitionPaperwork::PHASE_PR => $record->isPrApproved()
                || $record->pr_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            AcquisitionPaperwork::PHASE_PO => $record->isPoApproved()
                || $record->po_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            AcquisitionPaperwork::PHASE_IAR => $record->isIarApproved()
                || $record->iar_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            default => false,
        };
    }

    public static function exportPrAction(): Action
    {
        return Action::make('exportPr')
            ->label('Export PR')
            ->icon('heroicon-o-document-arrow-down')
            ->visible(fn (AcquisitionPaperwork $record): bool => filled($record->pr_number)
                || $record->pr_status !== AcquisitionPaperwork::STATUS_DRAFT)
            ->action(fn (AcquisitionPaperwork $record) => Redirect::away(route('owwa.export.acquisition-paperwork.pr', $record)));
    }

    public static function exportPoAction(): Action
    {
        return Action::make('exportPo')
            ->label('Export PO')
            ->icon('heroicon-o-document-arrow-down')
            ->visible(fn (AcquisitionPaperwork $record): bool => $record->isPrApproved())
            ->action(fn (AcquisitionPaperwork $record) => Redirect::away(route('owwa.export.acquisition-paperwork.po', $record)));
    }

    public static function exportIarAction(): Action
    {
        return Action::make('exportIar')
            ->label('Export IAR')
            ->icon('heroicon-o-document-arrow-down')
            ->visible(fn (AcquisitionPaperwork $record): bool => $record->isPoApproved())
            ->action(fn (AcquisitionPaperwork $record) => Redirect::away(route('owwa.export.acquisition-paperwork.iar', $record)));
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    public static function editModalFooterActions(EditAction $editAction): array
    {
        return [
            self::makeEditModalSubmitWorkflowAction(
                $editAction,
                'submitPrWorkflow',
                'submitPr',
                'Save & submit PR for export',
                fn (AcquisitionPaperwork $record): bool => ! $record->isPrApproved()
                    && $record->pr_status === AcquisitionPaperwork::STATUS_DRAFT,
            ),
            self::makeEditModalSubmitWorkflowAction(
                $editAction,
                'submitPoWorkflow',
                'submitPo',
                'Save & submit PO for export',
                fn (AcquisitionPaperwork $record): bool => $record->isPrApproved()
                    && ! $record->isPoApproved()
                    && $record->po_status === AcquisitionPaperwork::STATUS_DRAFT,
            ),
            self::makeEditModalSubmitWorkflowAction(
                $editAction,
                'submitIarWorkflow',
                'submitIar',
                'Save & submit IAR for export',
                fn (AcquisitionPaperwork $record): bool => $record->isPoApproved()
                    && ! $record->isIarApproved()
                    && $record->iar_status === AcquisitionPaperwork::STATUS_DRAFT,
            ),
            self::approvePrAction(),
            self::approvePoAction(),
            self::approveIarAction(),
            self::recordCustodyReceiptAction(),
            self::printUnitQrLabelsAction(),
            ActionGroup::make([
                self::exportPrAction(),
                self::exportPoAction(),
                self::exportIarAction(),
            ])
                ->label('Export')
                ->icon('heroicon-m-document-arrow-down')
                ->color('gray'),
        ];
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    public static function viewModalFooterActions(): array
    {
        return [
            self::approvePrAction(),
            self::approvePoAction(),
            self::approveIarAction(),
            self::recordCustodyReceiptAction(),
            self::printUnitQrLabelsAction(),
            ActionGroup::make([
                self::exportPrAction(),
                self::exportPoAction(),
                self::exportIarAction(),
            ])
                ->label('Export')
                ->icon('heroicon-m-document-arrow-down')
                ->color('gray'),
        ];
    }

    /**
     * @deprecated Use editModalFooterActions() or viewModalFooterActions() instead.
     *
     * @return array<int, Action|ActionGroup>
     */
    public static function modalFooterActions(): array
    {
        return self::viewModalFooterActions();
    }

    protected static function makeEditModalSubmitWorkflowAction(
        EditAction $editAction,
        string $actionName,
        string $workflow,
        string $label,
        callable $visible,
    ): Action {
        return $editAction
            ->makeModalSubmitAction($actionName, ['workflow' => $workflow])
            ->label($label)
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible($visible);
    }

    /**
     * Hidden modal actions so the workflow stepper can open PR/PO/IAR read-only views
     * via mountAction() while edit or view is already open.
     *
     * @return array<int, Action>
     */
    public static function hiddenPhaseViewActionsForStepper(): array
    {
        return [
            self::viewPrAction(),
            self::viewPoAction(),
            self::viewIarAction(),
        ];
    }

    /**
     * @param  array<int, Action>  $footerActions
     */
    protected static function phaseViewAction(string $name, string $heading, \Filament\Schemas\Components\Section $section, array $footerActions): Action
    {
        return Action::make($name)
            ->label($heading)
            ->modalHeading($heading)
            ->modalWidth('5xl')
            ->extraModalWindowAttributes(['class' => 'owwa-view-record-modal owwa-record-modal'])
            ->schema([$section])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->extraModalFooterActions($footerActions);
    }

    protected static function workflowAction(
        string $name,
        string $label,
        string $description,
        callable $visible,
        callable $handler,
        string $successTitle,
        string $successBody,
        ?string $phase = null,
        bool $fromEditModal = false,
        string $color = 'success',
    ): Action {
        $action = Action::make($name)
            ->label($label)
            ->icon('heroicon-o-check')
            ->color($color)
            ->visible($visible)
            ->requiresConfirmation()
            ->modalDescription($description)
            ->action(function (AcquisitionPaperwork $record, Action $action) use ($handler, $successTitle, $successBody, $phase): void {
                self::runWorkflowHandler($record, $handler, $action, $successTitle, $successBody, $phase);
            });

        if ($fromEditModal) {
            $action->visible(false);
        }

        return $action;
    }

    protected static function runWorkflowHandler(
        AcquisitionPaperwork $record,
        callable $handler,
        Action $action,
        ?string $successTitle = null,
        ?string $successBody = null,
        ?string $phase = null,
    ): void {
        try {
            $handler($record);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Action blocked')
                ->body(self::formatWorkflowBlockedMessage($exception, $record, $phase))
                ->danger()
                ->send();

            $action->halt();

            return;
        } catch (\RuntimeException $exception) {
            Notification::make()
                ->title('Action failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            $action->halt();

            return;
        }

        $record->refresh();

        if ($successTitle !== null) {
            Notification::make()
                ->title($successTitle)
                ->body($successBody ?? '')
                ->success()
                ->send();
        }
    }

    protected static function formatWorkflowBlockedMessage(
        ValidationException $exception,
        AcquisitionPaperwork $record,
        ?string $phase,
    ): string {
        $message = collect($exception->errors())->flatten()->first() ?? 'Validation failed.';

        if (! str_contains($message, 'Missing:') || $phase === null) {
            return $message;
        }

        $isDraft = match ($phase) {
            AcquisitionPaperwork::PHASE_PR => $record->pr_status === AcquisitionPaperwork::STATUS_DRAFT,
            AcquisitionPaperwork::PHASE_PO => $record->po_status === AcquisitionPaperwork::STATUS_DRAFT,
            AcquisitionPaperwork::PHASE_IAR => $record->iar_status === AcquisitionPaperwork::STATUS_DRAFT,
            default => false,
        };

        if (! $isDraft) {
            return $message;
        }

        return match ($phase) {
            AcquisitionPaperwork::PHASE_PR => 'PR details are not saved yet. Open Edit, fill the form, and use Save & submit for export.',
            AcquisitionPaperwork::PHASE_PO => 'PO details are not saved yet. Open Edit, fill the form, and use Save & submit for export.',
            AcquisitionPaperwork::PHASE_IAR => 'IAR details are not saved yet. Open Edit, fill the form, and use Save & submit for export.',
            default => $message,
        };
    }
}
