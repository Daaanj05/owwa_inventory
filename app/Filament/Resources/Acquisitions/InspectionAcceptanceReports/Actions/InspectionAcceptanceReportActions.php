<?php

namespace App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Actions;

use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\InspectionAcceptanceReport;
use App\Services\InspectionAcceptanceReportWorkflowService;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;

class InspectionAcceptanceReportActions
{
    public static function configureEditAction(): EditAction
    {
        return OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_WIDE)
            ->label('')
            ->tableIcon(null)
            ->extraAttributes(['class' => 'sr-only'])
            ->visible(fn (InspectionAcceptanceReport $record): bool => $record->isEditable())
            ->extraModalWindowAttributes(['class' => OwwaFormModalDefaults::MODAL_WINDOW_CLASS.' owwa-acquisition-paperwork-modal'])
            ->modalHeading(fn (InspectionAcceptanceReport $record): string => filled($record->number)
                ? 'Edit IAR '.$record->number
                : 'Edit inspection & acceptance')
            ->modalSubmitActionLabel('Save draft')
            ->after(function (InspectionAcceptanceReport $record, EditAction $action): void {
                $workflow = $action->getArguments()['workflow'] ?? null;
                if ($workflow !== 'submitIar') {
                    return;
                }

                self::runWorkflow(
                    $record->fresh() ?? $record,
                    fn (InspectionAcceptanceReport $iar) => app(InspectionAcceptanceReportWorkflowService::class)->submit($iar),
                    $action,
                    'IAR saved',
                    'Export the inspection report for signature, then mark Approved when signed.',
                );
            })
            ->extraModalFooterActions(fn (EditAction $editAction): array => [
                $editAction->makeModalSubmitAction('submitIarWorkflow', ['workflow' => 'submitIar'])
                    ->label('Save IAR')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (?InspectionAcceptanceReport $record): bool => $record?->isEditable() ?? false),
                self::approveAction(),
                self::recordCustodyReceiptAction(),
                self::exportExcelAction(),
                self::exportPdfAction(),
            ]);
    }

    public static function approveAction(): Action
    {
        return Action::make('approveIar')
            ->label('Approved')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (InspectionAcceptanceReport $record): bool => filled($record->submitted_at)
                && ($record->isDraft() || $record->isPendingApproval())
                && ! $record->isArchived())
            ->requiresConfirmation()
            ->modalHeading('Mark IAR as approved?')
            ->modalDescription('Confirm after the printed IAR has been signed. You can then record custodian receipt.')
            ->action(function (InspectionAcceptanceReport $record, Action $action): void {
                self::runWorkflow(
                    $record,
                    fn (InspectionAcceptanceReport $iar) => app(InspectionAcceptanceReportWorkflowService::class)->approve($iar),
                    $action,
                    'IAR approved',
                    'Record custodian receipt when stock is received.',
                );
            });
    }

    public static function recordCustodyReceiptAction(): Action
    {
        return Action::make('recordCustodyReceipt')
            ->label('Record custodian receipt')
            ->icon('heroicon-o-check')
            ->color('primary')
            ->visible(function (InspectionAcceptanceReport $record): bool {
                if (! $record->isApproved() || $record->isReceived() || $record->isArchived()) {
                    return false;
                }

                if ($record->date_received === null) {
                    return false;
                }

                return ! $record->date_received->copy()->startOfDay()->isFuture();
            })
            ->requiresConfirmation()
            ->modalDescription('Creates stock receipts using IAR quantities.')
            ->action(function (InspectionAcceptanceReport $record, Action $action): void {
                self::runWorkflow(
                    $record,
                    fn (InspectionAcceptanceReport $iar) => app(InspectionAcceptanceReportWorkflowService::class)->recordCustodyReceipts($iar),
                    $action,
                    'Custodian receipts recorded',
                    'Stock levels updated. Status is now Received.',
                );

                $record->refresh();
                $paperwork = $record->purchaseOrder?->purchaseRequest;

                if ($paperwork?->isReceived()) {
                    $action->redirect(\App\Filament\Resources\Acquisitions\AcquisitionResource::viewModalUrl($paperwork));
                }
            });
    }

    public static function archiveAction(): Action
    {
        return Action::make('archiveIar')
            ->label('Archive')
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->visible(fn (InspectionAcceptanceReport $record): bool => ! $record->isArchived()
                && ($record->isDraft() || $record->isPendingApproval()))
            ->requiresConfirmation()
            ->action(function (InspectionAcceptanceReport $record): void {
                app(InspectionAcceptanceReportWorkflowService::class)->archive($record);
                Notification::make()->title('IAR archived')->success()->send();
            });
    }

    public static function restoreAction(): Action
    {
        return Action::make('restoreIar')
            ->label('Restore')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (InspectionAcceptanceReport $record): bool => $record->isArchived())
            ->action(function (InspectionAcceptanceReport $record): void {
                app(InspectionAcceptanceReportWorkflowService::class)->restore($record);
                Notification::make()->title('IAR restored')->success()->send();
            });
    }

    public static function exportExcelAction(): Action
    {
        return Action::make('exportIarExcel')
            ->label('Export Excel')
            ->icon('heroicon-o-document-arrow-down')
            ->visible(fn (InspectionAcceptanceReport $record): bool => $record->missingFields() === [])
            ->action(function (InspectionAcceptanceReport $record, Action $action): void {
                self::startExport($action, route('owwa.export.inspection-acceptance-report.excel', $record));
            });
    }

    public static function exportPdfAction(): Action
    {
        return Action::make('exportIarPdf')
            ->label('Export PDF')
            ->icon('heroicon-o-document-text')
            ->visible(fn (InspectionAcceptanceReport $record): bool => $record->missingFields() === [])
            ->action(function (InspectionAcceptanceReport $record, Action $action): void {
                self::startExport($action, route('owwa.export.inspection-acceptance-report.pdf', $record));
            });
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    public static function viewModalFooterActions(): array
    {
        return [
            self::approveAction(),
            self::recordCustodyReceiptAction(),
            self::archiveAction(),
            self::restoreAction(),
            ActionGroup::make([
                self::exportExcelAction(),
                self::exportPdfAction(),
            ])
                ->label('Export IAR')
                ->icon('heroicon-m-document-arrow-down')
                ->color('gray')
                ->button(),
        ];
    }

    protected static function startExport(Action $action, string $url): void
    {
        $livewire = $action->getLivewire();
        OwwaExportBusyDispatcher::start(
            $livewire instanceof LivewireComponent ? $livewire : null,
            $url,
            'Preparing export…',
            'Building your OWWA form…',
        );
    }

    protected static function runWorkflow(
        InspectionAcceptanceReport $record,
        callable $handler,
        Action $action,
        string $successTitle,
        string $successBody,
    ): void {
        try {
            $handler($record);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Action blocked')
                ->body(collect($exception->errors())->flatten()->first() ?? 'Validation failed.')
                ->danger()
                ->send();
            $action->halt();

            return;
        }

        Notification::make()->title($successTitle)->body($successBody)->success()->send();
    }
}
