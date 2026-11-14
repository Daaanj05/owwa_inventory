<?php

namespace App\Filament\Resources\Requisitions\Actions;

use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Requisitions\Schemas\RequisitionInfolistSchema;
use App\Filament\Resources\Requisitions\Schemas\RequisitionIssuanceFormSchema;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Requisition;
use App\Models\User;
use App\Services\EmployeeRequisitionClosureService;
use App\Services\RequisitionFulfillmentService;
use App\Services\RequisitionPurchaseRequestService;
use App\Services\RequisitionStockSnapshotService;
use App\Services\RequisitionWorkflowNotificationService;
use App\Support\EmployeeRequisitionStatus;
use App\Support\RequisitionLineDisplay;
use App\Support\RequisitionStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Size;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CustodianRequisitionActions
{
    public static function isCustodianReviewTarget(Requisition $record): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->isSupplyCustodian()
            && $record->requestedBy?->role === User::ROLE_UNIT_CONSOLIDATOR;
    }

    public static function canAcceptAndIssue(Requisition $record): bool
    {
        if (! self::isCustodianReviewTarget($record)) {
            return false;
        }

        if ($record->hasPendingRisApproval()) {
            return false;
        }

        if (! $record->isPendingCustodianReview()) {
            return false;
        }

        return self::hasActionableIssueLines($record);
    }

    public static function canIssueRemainder(Requisition $record): bool
    {
        if (! self::isCustodianReviewTarget($record)) {
            return false;
        }

        if ($record->hasPendingRisApproval()) {
            return false;
        }

        if (! $record->isAccepted() || ! $record->hasRemainingToIssue()) {
            return false;
        }

        return self::hasActionableIssueLines($record, requireStock: true);
    }

    public static function canMarkRisApproved(Requisition $record): bool
    {
        return self::isCustodianReviewTarget($record)
            && $record->isRisForApproval()
            && is_array($record->pendingIssuePayload());
    }

    public static function canIssueApprovedStock(Requisition $record): bool
    {
        return self::isCustodianReviewTarget($record)
            && $record->isRisApprovedReadyToIssue()
            && is_array($record->pendingIssuePayload());
    }

    public static function canReviseRisApproval(Requisition $record): bool
    {
        return self::isCustodianReviewTarget($record)
            && $record->isRisApprovedReadyToIssue()
            && is_array($record->pendingIssuePayload());
    }

    public static function canEditForApproval(Requisition $record): bool
    {
        return self::isCustodianReviewTarget($record)
            && $record->isRisForApproval()
            && is_array($record->pendingIssuePayload());
    }

    /**
     * True when at least one remaining line can be issued from regional supply,
     * or still needs a first-time zero-stock acknowledgement (no issue remarks yet).
     * Closed employee source requests are skipped so Issue Remainder hides after
     * Close remaining balance.
     */
    public static function hasActionableIssueLines(Requisition $record, bool $requireStock = false): bool
    {
        $fulfillment = app(RequisitionFulfillmentService::class);
        $stockSnapshot = app(RequisitionStockSnapshotService::class);

        if ($fulfillment->hasSourceEndorsements($record)) {
            $record->loadMissing([
                'sourceEndorsements.sourceRequisition',
                'sourceEndorsements.requisitionItem',
            ]);

            foreach ($record->sourceEndorsements as $endorsement) {
                if ($endorsement->sourceRequisition?->closed_at !== null) {
                    continue;
                }

                $remaining = $fulfillment->endorsementRemainingQuantity($endorsement);
                if ($remaining <= 0) {
                    continue;
                }

                $stock = $stockSnapshot->issuableStockForItem((int) $endorsement->item_id);

                if ($stock > 0) {
                    return true;
                }

                if ($requireStock) {
                    continue;
                }

                if (blank($endorsement->requisitionItem?->issue_remarks)) {
                    return true;
                }
            }

            return false;
        }

        $record->loadMissing('items');

        foreach ($record->items as $line) {
            $remaining = $fulfillment->remainingQuantity($line);
            if ($remaining <= 0) {
                continue;
            }

            $stock = $stockSnapshot->issuableStockForItem((int) $line->item_id);

            if ($stock > 0) {
                return true;
            }

            if ($requireStock) {
                continue;
            }

            if (blank($line->issue_remarks)) {
                return true;
            }
        }

        return false;
    }

    public static function reviewAndIssueAction(): Action
    {
        return OwwaFormModalDefaults::apply(
            Action::make('acceptAndIssue')
                ->label('Review & issue')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalAlignment(Alignment::Start)
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalHeading('Review And Issue Stock')
                ->modalDescription(fn (Requisition $record): string|\Illuminate\Contracts\Support\Htmlable => RequisitionInfolistSchema::acceptIssueModalDescription($record))
                ->modalSubmitActionLabel('Save for approval')
                ->modalSubmitAction(fn (Action $action): Action => $action->size(Size::Small))
                ->modalCancelAction(fn (Action $action): Action => $action->size(Size::Small))
                ->visible(fn (Requisition $record): bool => self::canAcceptAndIssue($record))
                ->fillForm(fn (Requisition $record): array => RequisitionIssuanceFormSchema::defaultFormState($record, remainderOnly: false))
                ->form(fn (Requisition $record): array => RequisitionIssuanceFormSchema::issueModalFields($record, remainderOnly: false))
                ->action(function (Requisition $record, array $data, Action $action): void {
                    self::saveForApproval($record, $data, remainderOnly: false, action: $action);
                }),
            OwwaFormModalDefaults::WIDTH_WIDE,
            'owwa-requisition-issue-modal',
        );
    }

    /**
     * @deprecated Use reviewAndIssueAction()
     */
    public static function acceptAndIssueAction(): Action
    {
        return self::reviewAndIssueAction();
    }

    public static function issueRemainderAction(): Action
    {
        return OwwaFormModalDefaults::apply(
            Action::make('issueRemainder')
                ->label('Issue Remainder')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->requiresConfirmation()
                ->modalAlignment(Alignment::Start)
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalHeading('Issue Remainder From Requisition')
                ->modalDescription(fn (Requisition $record): string|\Illuminate\Contracts\Support\Htmlable => RequisitionInfolistSchema::acceptIssueModalDescription($record))
                ->modalSubmitActionLabel('Save for approval')
                ->modalSubmitAction(fn (Action $action): Action => $action->size(Size::Small))
                ->modalCancelAction(fn (Action $action): Action => $action->size(Size::Small))
                ->visible(fn (Requisition $record): bool => self::canIssueRemainder($record))
                ->fillForm(fn (Requisition $record): array => RequisitionIssuanceFormSchema::defaultFormState($record, remainderOnly: true))
                ->form(fn (Requisition $record): array => RequisitionIssuanceFormSchema::issueModalFields($record, remainderOnly: true))
                ->action(function (Requisition $record, array $data, Action $action): void {
                    self::saveForApproval($record, $data, remainderOnly: true, action: $action);
                }),
            OwwaFormModalDefaults::WIDTH_WIDE,
            'owwa-requisition-issue-modal',
        );
    }

    public static function editForApprovalAction(): Action
    {
        return OwwaFormModalDefaults::apply(
            Action::make('editRisForApproval')
                ->label('Edit for approval')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->requiresConfirmation()
                ->modalAlignment(Alignment::Start)
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalHeading('Edit RIS For Approval')
                ->modalDescription(fn (Requisition $record): string|\Illuminate\Contracts\Support\Htmlable => RequisitionInfolistSchema::acceptIssueModalDescription($record))
                ->modalSubmitActionLabel('Save for approval')
                ->modalSubmitAction(fn (Action $action): Action => $action->size(Size::Small))
                ->modalCancelAction(fn (Action $action): Action => $action->size(Size::Small))
                ->visible(fn (Requisition $record): bool => self::canEditForApproval($record))
                ->fillForm(fn (Requisition $record): array => RequisitionIssuanceFormSchema::defaultFormState(
                    $record,
                    remainderOnly: (bool) ($record->pendingIssuePayload()['remainder_only'] ?? false),
                ))
                ->form(fn (Requisition $record): array => RequisitionIssuanceFormSchema::issueModalFields(
                    $record,
                    remainderOnly: (bool) ($record->pendingIssuePayload()['remainder_only'] ?? false),
                ))
                ->action(function (Requisition $record, array $data, Action $action): void {
                    self::saveForApproval(
                        $record,
                        $data,
                        remainderOnly: (bool) ($record->pendingIssuePayload()['remainder_only'] ?? false),
                        action: $action,
                        notify: false,
                    );
                }),
            OwwaFormModalDefaults::WIDTH_WIDE,
            'owwa-requisition-issue-modal',
        );
    }

    public static function markRisApprovedAction(): Action
    {
        return Action::make('markRisApproved')
            ->label('Mark as approved')
            ->icon('heroicon-o-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Mark RIS as approved?')
            ->modalDescription('Confirm after the printed RIS has been signed. Stock is not issued until you choose Issue stock.')
            ->modalSubmitActionLabel('Yes, mark as approved')
            ->visible(fn (Requisition $record): bool => self::canMarkRisApproved($record))
            ->action(function (Requisition $record): void {
                $record->update([
                    'ris_approval_status' => Requisition::RIS_APPROVAL_APPROVED,
                ]);

                Notification::make()
                    ->title('RIS approved')
                    ->body('You can now issue stock for '.$record->reference_code.'.')
                    ->success()
                    ->send();
            });
    }

    public static function issueApprovedStockAction(): Action
    {
        return Action::make('issueApprovedStock')
            ->label('Issue stock')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Issue stock now?')
            ->modalDescription('This deducts regional stock and creates the issuance records for the saved quantities.')
            ->modalSubmitActionLabel('Yes, issue stock')
            ->visible(fn (Requisition $record): bool => self::canIssueApprovedStock($record))
            ->action(function (Requisition $record, Action $action): void {
                self::runApprovedIssue($record, $action);
            });
    }

    public static function reviseRisApprovalAction(): Action
    {
        return Action::make('reviseRisApproval')
            ->label('Revise')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Return RIS for revision?')
            ->modalDescription('The RIS returns to For approval. Stock is not moved. Export and re-sign after you change quantities.')
            ->modalSubmitActionLabel('Yes, revise')
            ->visible(fn (Requisition $record): bool => self::canReviseRisApproval($record))
            ->action(function (Requisition $record): void {
                $record->update([
                    'ris_approval_status' => Requisition::RIS_APPROVAL_FOR_APPROVAL,
                ]);

                Notification::make()
                    ->title('RIS returned for revision')
                    ->body('Update quantities or signatories, then export again before marking as approved.')
                    ->warning()
                    ->send();
            });
    }

    public static function canCloseRemainingBalance(Requisition $record): bool
    {
        if (! self::isCustodianReviewTarget($record)) {
            return false;
        }

        if (! $record->isAccepted()) {
            return false;
        }

        if ($record->hasPendingRisApproval()) {
            return false;
        }

        $record->loadMissing('sourceRequests');

        $sources = $record->sourceRequests->isNotEmpty()
            ? $record->sourceRequests
            : collect();

        foreach ($sources as $source) {
            if ($source->closed_at !== null || ! $source->isEmployeeRequest()) {
                continue;
            }

            $target = EmployeeRequisitionStatus::fulfillmentTargetTotal($source);
            $issued = EmployeeRequisitionStatus::issuedTotal($source);

            if ($issued > 0 && $issued < $target) {
                return true;
            }
        }

        return false;
    }

    public static function closeRemainingBalanceAction(): Action
    {
        return Action::make('closeRemainingBalance')
            ->label('Close remaining balance')
            ->icon('heroicon-o-lock-closed')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Close remaining balance')
            ->modalDescription('Issued quantities stay as they are. Linked employee requests that are still short will be closed, and the remaining quantity will not be issued.')
            ->modalSubmitActionLabel('Yes, close remaining balance')
            ->visible(fn (Requisition $record): bool => self::canCloseRemainingBalance($record))
            ->action(function (Requisition $record): void {
                $user = Auth::user();

                if (! $user instanceof User) {
                    return;
                }

                $result = app(EmployeeRequisitionClosureService::class)
                    ->closeRemainingBalanceByCustodian($record, $user);

                $closed = (int) ($result['closed'] ?? 0);

                if ($closed <= 0) {
                    Notification::make()
                        ->title('Nothing to close')
                        ->body('No partially issued employee requests were still open.')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Remaining balance closed')
                    ->body($closed === 1
                        ? '1 employee request was closed.'
                        : "{$closed} employee requests were closed.")
                    ->success()
                    ->send();
            });
    }

    public static function createPurchaseRequestAction(): Action
    {
        return Action::make('createPurchaseRequest')
            ->label('Create PR')
            ->icon('heroicon-o-document-plus')
            ->color('primary')
            ->modalHeading('Create purchase request')
            ->modalDescription('Only remaining lines whose current regional stock is exactly zero will be copied.')
            ->form(fn (Requisition $record): array => [
                Select::make('category_id')
                    ->label('Item category')
                    ->options(app(RequisitionPurchaseRequestService::class)->eligibleCategoryOptions($record))
                    ->default(fn (): ?int => app(RequisitionPurchaseRequestService::class)->eligibleCategoryIds($record)[0] ?? null)
                    ->required()
                    ->visible(fn (): bool => count(app(RequisitionPurchaseRequestService::class)->eligibleCategoryIds($record)) > 1),
            ])
            ->visible(function (Requisition $record): bool {
                $user = Auth::user();

                return $user instanceof User
                    && $user->isSupplyCustodian()
                    && ! $record->hasPendingRisApproval()
                    && app(RequisitionPurchaseRequestService::class)->canCreatePurchaseRequest($record);
            })
            ->action(function (Requisition $record, array $data, Action $action): void {
                $service = app(RequisitionPurchaseRequestService::class);
                $eligibleCategoryIds = $service->eligibleCategoryIds($record);
                $categoryId = (int) ($data['category_id'] ?? ($eligibleCategoryIds[0] ?? 0));

                if (! in_array($categoryId, $eligibleCategoryIds, true)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'category_id' => 'This category no longer has eligible zero-stock lines.',
                    ]);
                }

                $action->redirect(AcquisitionResource::getUrl('index', [
                    'category' => $categoryId,
                    'create_from_requisition' => $record->id,
                ]));
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function saveForApproval(
        Requisition $record,
        array $data,
        bool $remainderOnly,
        ?Action $action = null,
        bool $notify = true,
    ): void {
        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        $rows = self::filterIssueRows($data['lines'] ?? [], acknowledgeZeroQuantity: ! $remainderOnly);

        if ($rows === []) {
            Notification::make()
                ->title('Nothing to save')
                ->body('Enter a quantity to issue or add issue remarks for backordered lines.')
                ->warning()
                ->send();

            $action?->halt();

            return;
        }

        $signatories = self::signatoriesFromForm($data);

        app(RequisitionFulfillmentService::class)->persistRisSignatoriesPublic($record, $signatories);

        $record->update([
            'ris_approval_status' => Requisition::RIS_APPROVAL_FOR_APPROVAL,
            'pending_issue_payload' => [
                'issuance_date' => (string) ($data['issuance_date'] ?? now()->toDateString()),
                'remainder_only' => $remainderOnly,
                'lines' => $rows,
                'signatories' => $signatories,
            ],
        ]);

        if ($notify) {
            app(RequisitionWorkflowNotificationService::class)->handleRisSavedForApproval($record->fresh() ?? $record, $rows);
        }

        Notification::make()
            ->title('Saved for approval')
            ->body('Export the RIS PDF for signature, then mark as approved before issuing stock.')
            ->success()
            ->send();
    }

    protected static function runApprovedIssue(Requisition $record, ?Action $action = null): void
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        if (! $record->isRisApprovedReadyToIssue()) {
            Notification::make()
                ->title('Could not issue stock')
                ->body('Mark the RIS as approved after the wet signature before issuing stock.')
                ->danger()
                ->send();

            $action?->halt();

            return;
        }

        $payload = $record->pendingIssuePayload();
        if ($payload === null) {
            Notification::make()
                ->title('Could not issue stock')
                ->body('No prepared quantities were found for this RIS.')
                ->danger()
                ->send();

            $action?->halt();

            return;
        }

        $rows = self::filterIssueRows($payload['lines'] ?? [], acknowledgeZeroQuantity: ! (bool) ($payload['remainder_only'] ?? false));
        $signatories = is_array($payload['signatories'] ?? null)
            ? $payload['signatories']
            : self::signatoriesFromRecord($record);

        try {
            $result = app(RequisitionFulfillmentService::class)->issueLines(
                $record,
                $user,
                $rows,
                (string) ($payload['issuance_date'] ?? now()->toDateString()),
                $signatories,
            );
        } catch (ValidationException|InvalidArgumentException $exception) {
            $message = $exception instanceof ValidationException
                ? (collect($exception->errors())->flatten()->first() ?? $exception->getMessage())
                : $exception->getMessage();

            Notification::make()
                ->title('Could not issue stock')
                ->body(is_string($message) && $message !== '' ? $message : 'Could not issue this requisition.')
                ->danger()
                ->send();

            $action?->halt();

            return;
        }

        $record->update([
            'ris_approval_status' => null,
            'pending_issue_payload' => null,
        ]);

        $created = (int) ($result['created'] ?? 0);
        $acknowledged = (int) ($result['acknowledged'] ?? 0);
        $categoryCounts = (array) ($result['categories'] ?? []);

        if ($created > 0) {
            $record->refresh();

            Notification::make()
                ->title('Stock issued')
                ->body(RequisitionLineDisplay::formatIssuanceCategorySummary($created, $categoryCounts).' Status: '.RequisitionStatus::label($record->status).'.')
                ->success()
                ->send();
        } elseif ($acknowledged > 0) {
            $record->refresh();

            Notification::make()
                ->title('Backorder recorded')
                ->body('RIS '.$record->reference_code.' acknowledged — awaiting regional stock.')
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title('No stock was issued')
                ->body('Enter a quantity to issue or add issue remarks for backordered lines.')
                ->warning()
                ->send();
        }
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected static function filterIssueRows(array $lines, bool $acknowledgeZeroQuantity): array
    {
        return collect($lines)
            ->filter(function (mixed $row) use ($acknowledgeZeroQuantity): bool {
                if (! is_array($row)) {
                    return false;
                }

                $lineId = (int) ($row['requisition_item_id'] ?? 0);
                $endorsementId = (int) ($row['source_endorsement_id'] ?? 0);

                if ($lineId <= 0 && $endorsementId <= 0) {
                    return false;
                }

                $qty = (int) ($row['quantity_to_issue'] ?? 0);

                return $qty > 0 || ($acknowledgeZeroQuantity && filled($row['issue_remarks'] ?? null));
            })
            ->unique(fn (array $row): string => ($row['source_endorsement_id'] ?? null) !== null
                ? 'endorsement:'.(int) $row['source_endorsement_id']
                : 'item:'.(int) $row['requisition_item_id'])
            ->map(fn (array $row): array => [
                'requisition_item_id' => (int) ($row['requisition_item_id'] ?? 0) ?: null,
                'source_endorsement_id' => (int) ($row['source_endorsement_id'] ?? 0) ?: null,
                'quantity_to_issue' => (int) ($row['quantity_to_issue'] ?? 0),
                'issue_remarks' => filled($row['issue_remarks'] ?? null) ? (string) $row['issue_remarks'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function signatoriesFromForm(array $data): array
    {
        return [
            'ris_requested_by_name' => $data['ris_requested_by_name'] ?? null,
            'ris_requested_by_designation' => $data['ris_requested_by_designation'] ?? null,
            'ris_requested_by_date' => $data['ris_requested_by_date'] ?? null,
            'ris_approved_by_name' => $data['ris_approved_by_name'] ?? null,
            'ris_approved_by_designation' => $data['ris_approved_by_designation'] ?? null,
            'ris_approved_by_date' => $data['ris_approved_by_date'] ?? null,
            'ris_issued_by_name' => $data['ris_issued_by_name'] ?? null,
            'ris_issued_by_designation' => $data['ris_issued_by_designation'] ?? null,
            'ris_issued_by_date' => $data['ris_issued_by_date'] ?? null,
            'ris_received_by_name' => $data['ris_received_by_name'] ?? null,
            'ris_received_by_designation' => $data['ris_received_by_designation'] ?? null,
            'ris_received_by_date' => $data['ris_received_by_date'] ?? null,
            'custodian_printed_name' => $data['ris_issued_by_name'] ?? null,
            'custodian_designation' => $data['ris_issued_by_designation'] ?? null,
            'issued_to_designation' => $data['ris_received_by_designation'] ?? null,
            'received_by_printed_name' => $data['ris_received_by_name'] ?? null,
            'received_from_name' => $data['ris_issued_by_name'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function signatoriesFromRecord(Requisition $record): array
    {
        return [
            'ris_requested_by_name' => $record->ris_requested_by_name,
            'ris_requested_by_designation' => $record->ris_requested_by_designation,
            'ris_requested_by_date' => $record->ris_requested_by_date?->toDateString(),
            'ris_approved_by_name' => $record->ris_approved_by_name,
            'ris_approved_by_designation' => $record->ris_approved_by_designation,
            'ris_approved_by_date' => $record->ris_approved_by_date?->toDateString(),
            'ris_issued_by_name' => $record->ris_issued_by_name,
            'ris_issued_by_designation' => $record->ris_issued_by_designation,
            'ris_issued_by_date' => $record->ris_issued_by_date?->toDateString(),
            'ris_received_by_name' => $record->ris_received_by_name,
            'ris_received_by_designation' => $record->ris_received_by_designation,
            'ris_received_by_date' => $record->ris_received_by_date?->toDateString(),
            'custodian_printed_name' => $record->ris_issued_by_name,
            'custodian_designation' => $record->ris_issued_by_designation,
            'issued_to_designation' => $record->ris_received_by_designation,
            'received_by_printed_name' => $record->ris_received_by_name,
            'received_from_name' => $record->ris_issued_by_name,
        ];
    }
}
