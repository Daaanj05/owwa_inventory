<?php

namespace App\Filament\Resources\Acquisitions\Paperwork\Actions;

use App\Filament\Resources\Acquisitions\Paperwork\Schemas\AcquisitionPaperworkInfolist;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\AcquisitionPaperwork;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Services\InventoryQrLabelService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Redirect;

class AcquisitionPaperworkActions
{
    public static function submitPrAction(): Action
    {
        return self::workflowAction(
            name: 'submitPr',
            label: 'Submit PR for approval',
            description: 'Locks PR fields for export. Print Appendix 60 and route for offline approval.',
            visible: fn (AcquisitionPaperwork $record): bool => ! $record->isPrApproved()
                && $record->pr_status === AcquisitionPaperwork::STATUS_DRAFT,
            handler: fn (AcquisitionPaperwork $record) => app(AcquisitionPaperworkCompletionService::class)->submitPr($record),
            successTitle: 'PR submitted',
            successBody: 'Export Appendix 60 and obtain offline approval.',
        );
    }

    public static function approvePrAction(): Action
    {
        return self::workflowAction(
            name: 'approvePr',
            label: 'Mark PR approved',
            description: 'Assigns PR No. and unlocks PO after offline approval is recorded.',
            visible: fn (AcquisitionPaperwork $record): bool => $record->pr_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            handler: fn (AcquisitionPaperwork $record) => app(AcquisitionPaperworkCompletionService::class)->approvePr($record),
            successTitle: 'PR approved',
            successBody: 'PO phase is now unlocked.',
        );
    }

    public static function submitPoAction(): Action
    {
        return self::workflowAction(
            name: 'submitPo',
            label: 'Submit PO for approval',
            description: 'Export Appendix 61 and route for offline approval.',
            visible: fn (AcquisitionPaperwork $record): bool => $record->isPrApproved()
                && ! $record->isPoApproved()
                && $record->po_status === AcquisitionPaperwork::STATUS_DRAFT,
            handler: fn (AcquisitionPaperwork $record) => app(AcquisitionPaperworkCompletionService::class)->submitPo($record),
            successTitle: 'PO submitted',
            successBody: 'Export Appendix 61 for the supplier.',
        );
    }

    public static function approvePoAction(): Action
    {
        return self::workflowAction(
            name: 'approvePo',
            label: 'Mark PO approved',
            description: 'Assigns PO No. and unlocks IAR.',
            visible: fn (AcquisitionPaperwork $record): bool => $record->po_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            handler: fn (AcquisitionPaperwork $record) => app(AcquisitionPaperworkCompletionService::class)->approvePo($record),
            successTitle: 'PO approved',
            successBody: 'IAR phase is now unlocked.',
        );
    }

    public static function submitIarAction(): Action
    {
        return self::workflowAction(
            name: 'submitIar',
            label: 'Submit IAR for approval',
            description: 'Export Appendix 62 and route for offline inspection sign-off.',
            visible: fn (AcquisitionPaperwork $record): bool => $record->isPoApproved()
                && ! $record->isIarApproved()
                && $record->iar_status === AcquisitionPaperwork::STATUS_DRAFT,
            handler: fn (AcquisitionPaperwork $record) => app(AcquisitionPaperworkCompletionService::class)->submitIar($record),
            successTitle: 'IAR submitted',
            successBody: 'Export Appendix 62 and file with records.',
        );
    }

    public static function approveIarAction(): Action
    {
        return self::workflowAction(
            name: 'approveIar',
            label: 'Mark IAR approved',
            description: 'Assigns IAR No. You can then record custodian receipt when goods arrive.',
            visible: fn (AcquisitionPaperwork $record): bool => $record->iar_status === AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            handler: fn (AcquisitionPaperwork $record) => app(AcquisitionPaperworkCompletionService::class)->approveIar($record),
            successTitle: 'IAR approved',
            successBody: 'Record custodian receipt when stock is received.',
        );
    }

    public static function recordCustodyReceiptAction(): Action
    {
        return self::workflowAction(
            name: 'recordCustodyReceipt',
            label: 'Record custodian receipt',
            description: 'Creates one custodian receipt per line and updates stock levels.',
            visible: fn (AcquisitionPaperwork $record): bool => $record->isIarApproved() && ! $record->isReceived(),
            handler: fn (AcquisitionPaperwork $record) => app(AcquisitionPaperworkCompletionService::class)->recordCustodyReceipts($record),
            successTitle: 'Custodian receipts recorded',
            successBody: 'Stock levels updated. Status is now Received — the case stays on the All acquisitions list.',
            color: 'primary',
        );
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
        return self::phaseViewAction('viewPr', 'PR details', AcquisitionPaperworkInfolist::prSection(), [
            self::exportPrAction(),
            self::submitPrAction(),
            self::approvePrAction(),
        ]);
    }

    public static function viewPoAction(): Action
    {
        return self::phaseViewAction('viewPo', 'PO details', AcquisitionPaperworkInfolist::poSection(), [
            self::exportPoAction(),
            self::submitPoAction(),
            self::approvePoAction(),
        ]);
    }

    public static function viewIarAction(): Action
    {
        return self::phaseViewAction('viewIar', 'IAR details', AcquisitionPaperworkInfolist::iarSection(), [
            self::exportIarAction(),
            self::submitIarAction(),
            self::approveIarAction(),
        ]);
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

    public static function editAction(): EditAction
    {
        return OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_WIDE)
            ->visible(fn (AcquisitionPaperwork $record): bool => ! $record->isReceived());
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    public static function modalFooterActions(): array
    {
        return [
            self::submitPrAction(),
            self::approvePrAction(),
            self::submitPoAction(),
            self::approvePoAction(),
            self::submitIarAction(),
            self::approveIarAction(),
            self::recordCustodyReceiptAction(),
            self::printUnitQrLabelsAction(),
            self::viewPrAction(),
            self::viewPoAction(),
            self::viewIarAction(),
            ActionGroup::make([
                self::exportPrAction(),
                self::exportPoAction(),
                self::exportIarAction(),
                self::editAction(),
            ])
                ->label('More')
                ->icon('heroicon-m-ellipsis-horizontal')
                ->color('gray'),
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
        string $color = 'success',
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-check')
            ->color($color)
            ->visible($visible)
            ->requiresConfirmation()
            ->modalDescription($description)
            ->action(function (AcquisitionPaperwork $record, Action $action) use ($handler, $successTitle, $successBody): void {
                try {
                    $handler($record);
                } catch (\Illuminate\Validation\ValidationException $exception) {
                    Notification::make()
                        ->title('Action blocked')
                        ->body(collect($exception->errors())->flatten()->first() ?? 'Validation failed.')
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

                Notification::make()
                    ->title($successTitle)
                    ->body($successBody)
                    ->success()
                    ->send();
            });
    }
}
