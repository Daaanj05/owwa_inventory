<?php

namespace App\Filament\Resources\Acquisitions\PurchaseOrders\Actions;

use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderWorkflowService;
use App\Support\OwwaExportBusyDispatcher;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;

class PurchaseOrderActions
{
    public static function configureEditAction(): EditAction
    {
        return OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_WIDE)
            ->label('')
            ->tableIcon(null)
            ->extraAttributes(['class' => 'sr-only'])
            ->visible(fn (PurchaseOrder $record): bool => $record->isEditable())
            ->extraModalWindowAttributes(['class' => OwwaFormModalDefaults::MODAL_WINDOW_CLASS.' owwa-acquisition-paperwork-modal'])
            ->modalHeading(fn (PurchaseOrder $record): string => filled($record->number)
                ? 'Edit PO '.$record->number
                : 'Edit purchase order')
            ->modalSubmitActionLabel('Save draft')
            ->after(function (PurchaseOrder $record, EditAction $action): void {
                app(PurchaseOrderWorkflowService::class)->rememberSupplier($record);

                $workflow = $action->getArguments()['workflow'] ?? null;
                if ($workflow !== 'submitPo') {
                    return;
                }

                self::runWorkflow(
                    $record->fresh() ?? $record,
                    fn (PurchaseOrder $po) => app(PurchaseOrderWorkflowService::class)->submit($po),
                    $action,
                    'PO submitted',
                    'Export the purchase order for offline approval.',
                );
            })
            ->extraModalFooterActions(fn (EditAction $editAction): array => [
                $editAction->makeModalSubmitAction('submitPoWorkflow', ['workflow' => 'submitPo'])
                    ->label('Save PO')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (?PurchaseOrder $record): bool => $record?->isEditable() ?? false),
                self::approveAction(),
                self::exportExcelAction(),
                self::exportPdfAction(),
            ]);
    }

    public static function approveAction(): Action
    {
        return Action::make('approvePo')
            ->label('Record Offline Approval')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (PurchaseOrder $record): bool => $record->isPendingApproval() && ! $record->isArchived())
            ->requiresConfirmation()
            ->modalDescription('Assigns PO No. after offline approval is recorded.')
            ->action(function (PurchaseOrder $record, Action $action): void {
                self::runWorkflow(
                    $record,
                    fn (PurchaseOrder $po) => app(PurchaseOrderWorkflowService::class)->approve($po),
                    $action,
                    'PO approved',
                    'You can now create an IAR from this purchase order.',
                );
            });
    }

    public static function archiveAction(): Action
    {
        return Action::make('archivePo')
            ->label('Archive')
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->visible(fn (PurchaseOrder $record): bool => ! $record->isArchived()
                && ($record->isDraft() || $record->isPendingApproval()))
            ->requiresConfirmation()
            ->action(function (PurchaseOrder $record): void {
                app(PurchaseOrderWorkflowService::class)->archive($record);
                Notification::make()->title('PO archived')->success()->send();
            });
    }

    public static function restoreAction(): Action
    {
        return Action::make('restorePo')
            ->label('Restore')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (PurchaseOrder $record): bool => $record->isArchived())
            ->action(function (PurchaseOrder $record): void {
                app(PurchaseOrderWorkflowService::class)->restore($record);
                Notification::make()->title('PO restored')->success()->send();
            });
    }

    public static function exportExcelAction(): Action
    {
        return Action::make('exportPoExcel')
            ->label('Export Excel')
            ->icon('heroicon-o-document-arrow-down')
            ->visible(fn (PurchaseOrder $record): bool => ! $record->isDraft() || filled($record->number))
            ->action(function (PurchaseOrder $record, Action $action): void {
                self::startExport($action, route('owwa.export.purchase-order.excel', $record));
            });
    }

    public static function exportPdfAction(): Action
    {
        return Action::make('exportPoPdf')
            ->label('Export PDF')
            ->icon('heroicon-o-document-text')
            ->visible(fn (PurchaseOrder $record): bool => ! $record->isDraft() || filled($record->number))
            ->action(function (PurchaseOrder $record, Action $action): void {
                self::startExport($action, route('owwa.export.purchase-order.pdf', $record));
            });
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    public static function viewModalFooterActions(): array
    {
        return [
            self::approveAction(),
            self::archiveAction(),
            self::restoreAction(),
            ActionGroup::make([
                self::exportExcelAction(),
                self::exportPdfAction(),
            ])
                ->label('Export PO')
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
        PurchaseOrder $record,
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
