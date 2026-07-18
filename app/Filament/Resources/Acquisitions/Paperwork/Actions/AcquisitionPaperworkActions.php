<?php

namespace App\Filament\Resources\Acquisitions\Paperwork\Actions;

use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Acquisitions\Paperwork\Schemas\AcquisitionPaperworkInfolist;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\AcquisitionPaperwork;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Services\InventoryQrLabelService;
use App\Services\RequisitionPurchaseRequestService;
use App\Support\AcquisitionPaperworkViewPresenter;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;

class AcquisitionPaperworkActions
{
    public static function configureEditAction(): EditAction
    {
        return OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_WIDE)
            ->label('')
            ->tableIcon(null)
            ->extraAttributes(['class' => 'sr-only'])
            ->visible(fn (AcquisitionPaperwork $record): bool => $record->isPrEditable())
            ->extraModalWindowAttributes(['class' => OwwaFormModalDefaults::MODAL_WINDOW_CLASS.' owwa-acquisition-paperwork-modal'])
            ->modalHeading(fn (AcquisitionPaperwork $record): string => AcquisitionPaperworkViewPresenter::editModalHeading($record))
            ->modalSubmitActionLabel('Save draft')
            ->modalSubmitAction(fn (Action $action): Action => $action->visible(
                fn (?AcquisitionPaperwork $record): bool => $record !== null
                    && $record->isPrEditable(),
            ))
            ->after(function (AcquisitionPaperwork $record, EditAction $action): void {
                if ($record->pr_status === AcquisitionPaperwork::STATUS_DRAFT) {
                    app(RequisitionPurchaseRequestService::class)->linkSelectedSources(
                        $record,
                        $record->requisitions()->pluck('requisitions.id')->all(),
                    );
                }

                $workflow = $action->getArguments()['workflow'] ?? null;

                if ($workflow !== 'submitPr') {
                    return;
                }

                self::runWorkflowHandler(
                    $record->fresh(),
                    fn (AcquisitionPaperwork $paperwork) => app(AcquisitionPaperworkCompletionService::class)->submitPr($paperwork),
                    $action,
                    'PR submitted',
                    'Export the purchase request and route for offline approval.',
                    AcquisitionPaperwork::PHASE_PR,
                );
            })
            ->extraModalFooterActions(fn (EditAction $editAction): array => self::editModalFooterActions($editAction));
    }

    public static function submitPrAction(bool $fromEditModal = false): Action
    {
        return self::workflowAction(
            name: 'submitPr',
            label: 'Save PR',
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
            label: 'Record Offline Approval',
            description: 'Assigns PR No. and unlocks PO after offline approval is recorded.',
            visible: fn (AcquisitionPaperwork $record): bool => $record->pr_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            handler: fn (AcquisitionPaperwork $record) => app(AcquisitionPaperworkCompletionService::class)->approvePr($record),
            successTitle: 'PR approved',
            successBody: 'Create a purchase order from the PO tab by choosing this PR.',
            phase: AcquisitionPaperwork::PHASE_PR,
        );
    }

    public static function archiveAction(): Action
    {
        return Action::make('archivePr')
            ->label('Archive')
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->visible(fn (AcquisitionPaperwork $record): bool => ! $record->isArchived()
                && ($record->isPrEditable() || $record->isPrPendingApproval()))
            ->requiresConfirmation()
            ->action(function (AcquisitionPaperwork $record): void {
                app(AcquisitionPaperworkCompletionService::class)->archive($record);
                Notification::make()->title('PR archived')->success()->send();
            });
    }

    public static function restoreAction(): Action
    {
        return Action::make('restorePr')
            ->label('Restore')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (AcquisitionPaperwork $record): bool => $record->isArchived())
            ->action(function (AcquisitionPaperwork $record): void {
                app(AcquisitionPaperworkCompletionService::class)->restore($record);
                Notification::make()->title('PR restored')->success()->send();
            });
    }

    public static function submitPoAction(bool $fromEditModal = false): Action
    {
        return self::workflowAction(
            name: 'submitPo',
            label: 'Save PO',
            description: 'Use the PO tab to create and submit purchase orders.',
            visible: fn (): bool => false,
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
            label: 'Record Offline Approval',
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
            label: 'Save IAR',
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
            label: 'Record Offline Approval',
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

    public static function exportPrAction(): Action
    {
        return Action::make('exportPr')
            ->label('Export Excel')
            ->icon('heroicon-o-document-arrow-down')
            ->visible(fn (AcquisitionPaperwork $record): bool => filled($record->pr_number)
                || $record->pr_status !== AcquisitionPaperwork::STATUS_DRAFT)
            ->action(function (AcquisitionPaperwork $record, Action $action): void {
                self::startOwwaExport($action, route('owwa.export.acquisition-paperwork.pr', $record));
            });
    }

    public static function exportPrPdfAction(): Action
    {
        return Action::make('exportPrPdf')
            ->label('Export PDF')
            ->icon('heroicon-o-document-text')
            ->visible(fn (AcquisitionPaperwork $record): bool => filled($record->pr_number)
                || $record->pr_status !== AcquisitionPaperwork::STATUS_DRAFT)
            ->action(function (AcquisitionPaperwork $record, Action $action): void {
                self::startOwwaExport($action, route('owwa.export.acquisition-paperwork.pr-pdf', $record));
            });
    }

    public static function exportPoAction(): Action
    {
        return Action::make('exportPo')
            ->label('Export PO')
            ->icon('heroicon-o-document-arrow-down')
            ->visible(fn (AcquisitionPaperwork $record): bool => $record->purchaseOrder !== null
                && ! $record->purchaseOrder->isDraft())
            ->action(function (AcquisitionPaperwork $record, Action $action): void {
                self::startOwwaExport($action, route('owwa.export.purchase-order.excel', $record->purchaseOrder));
            });
    }

    public static function exportIarAction(): Action
    {
        return Action::make('exportIar')
            ->label('Export IAR')
            ->icon('heroicon-o-document-arrow-down')
            ->visible(fn (AcquisitionPaperwork $record): bool => $record->purchaseOrder?->inspectionAcceptanceReport !== null
                && ! $record->purchaseOrder->inspectionAcceptanceReport->isDraft())
            ->action(function (AcquisitionPaperwork $record, Action $action): void {
                self::startOwwaExport(
                    $action,
                    route('owwa.export.inspection-acceptance-report.excel', $record->purchaseOrder->inspectionAcceptanceReport),
                );
            });
    }

    protected static function startOwwaExport(Action $action, string $url): void
    {
        $livewire = $action->getLivewire();
        OwwaExportBusyDispatcher::start(
            $livewire instanceof LivewireComponent ? $livewire : null,
            $url,
            'Preparing Excel export…',
            'Building your OWWA form…',
        );
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
                'Save PR',
                fn (AcquisitionPaperwork $record): bool => $record->isPrEditable(),
            ),
            self::approvePrAction(),
            self::archiveAction(),
            self::restoreAction(),
            ActionGroup::make([
                self::exportPrAction(),
                self::exportPrPdfAction(),
            ])
                ->label('Export PR')
                ->icon('heroicon-m-document-arrow-down')
                ->color('gray')
                ->button(),
        ];
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    public static function viewModalFooterActions(): array
    {
        return [
            self::approvePrAction(),
            self::archiveAction(),
            self::restoreAction(),
            ActionGroup::make([
                self::exportPrAction(),
                self::exportPrPdfAction(),
            ])
                ->label('Export PR')
                ->icon('heroicon-m-document-arrow-down')
                ->color('gray')
                ->button(),
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
            ->extraModalWindowAttributes(['class' => OwwaFormModalDefaults::MODAL_WINDOW_CLASS])
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
