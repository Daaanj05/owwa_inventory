<?php

namespace App\Filament\Resources\Requisitions\Actions;

use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Requisitions\Schemas\RequisitionInfolistSchema;
use App\Filament\Resources\Requisitions\Schemas\RequisitionIssuanceFormSchema;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\Requisition;
use App\Models\User;
use App\Services\RequisitionFulfillmentService;
use App\Services\RequisitionPurchaseRequestService;
use App\Services\RequisitionStockSnapshotService;
use App\Support\RequisitionLineDisplay;
use App\Support\RequisitionStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Size;
use Illuminate\Support\Facades\Auth;

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

        if (! $record->isAccepted() || ! $record->hasRemainingToIssue()) {
            return false;
        }

        return self::hasActionableIssueLines($record, requireStock: true);
    }

    /**
     * True when at least one remaining line can be issued from regional supply,
     * or still needs a first-time zero-stock acknowledgement (no issue remarks yet).
     */
    public static function hasActionableIssueLines(Requisition $record, bool $requireStock = false): bool
    {
        $record->loadMissing('items');
        $fulfillment = app(RequisitionFulfillmentService::class);
        $stockSnapshot = app(RequisitionStockSnapshotService::class);

        foreach ($record->items as $line) {
            $remaining = $fulfillment->remainingQuantity($line);
            if ($remaining <= 0) {
                continue;
            }

            $stock = $stockSnapshot->regionalStockForItem((int) $line->item_id);

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
                ->modalSubmitActionLabel('Yes, Issue Stock')
                ->modalSubmitAction(fn (Action $action): Action => $action->size(Size::Small))
                ->modalCancelAction(fn (Action $action): Action => $action->size(Size::Small))
                ->visible(fn (Requisition $record): bool => self::canAcceptAndIssue($record))
                ->fillForm(fn (Requisition $record): array => RequisitionIssuanceFormSchema::defaultFormState($record, remainderOnly: false))
                ->form(fn (Requisition $record): array => RequisitionIssuanceFormSchema::issueModalFields($record, remainderOnly: false))
                ->action(function (Requisition $record, array $data): void {
                    self::runIssueAction($record, $data, 'Stock issued');
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
                ->modalSubmitActionLabel('Yes, Issue Remainder')
                ->modalSubmitAction(fn (Action $action): Action => $action->size(Size::Small))
                ->modalCancelAction(fn (Action $action): Action => $action->size(Size::Small))
                ->visible(fn (Requisition $record): bool => self::canIssueRemainder($record))
                ->fillForm(fn (Requisition $record): array => RequisitionIssuanceFormSchema::defaultFormState($record, remainderOnly: true))
                ->form(fn (Requisition $record): array => RequisitionIssuanceFormSchema::issueModalFields($record, remainderOnly: true))
                ->action(function (Requisition $record, array $data): void {
                    self::runIssueAction($record, $data, 'Stock issued', acknowledgeZeroQuantity: false);
                }),
            OwwaFormModalDefaults::WIDTH_WIDE,
            'owwa-requisition-issue-modal',
        );
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
    protected static function runIssueAction(
        Requisition $record,
        array $data,
        string $successTitle,
        bool $acknowledgeZeroQuantity = true,
    ): void {
        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        $rows = collect($data['lines'] ?? [])
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
            ->values()
            ->all();

        $result = app(RequisitionFulfillmentService::class)->issueLines(
            $record,
            $user,
            $rows,
            (string) ($data['issuance_date'] ?? now()->toDateString()),
            [
                'custodian_printed_name' => $data['custodian_printed_name'] ?? null,
                'custodian_designation' => $data['custodian_designation'] ?? null,
                'issued_to_designation' => $data['issued_to_designation'] ?? null,
                'accounting_staff_printed_name' => $data['accounting_staff_printed_name'] ?? null,
            ],
        );

        $created = (int) ($result['created'] ?? 0);
        $acknowledged = (int) ($result['acknowledged'] ?? 0);
        $categoryCounts = (array) ($result['categories'] ?? []);

        if ($created > 0) {
            $record->refresh();

            Notification::make()
                ->title($successTitle)
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
}
