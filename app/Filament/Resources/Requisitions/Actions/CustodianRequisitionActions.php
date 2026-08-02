<?php

namespace App\Filament\Resources\Requisitions\Actions;

use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Requisitions\Schemas\RequisitionInfolistSchema;
use App\Filament\Resources\Requisitions\Schemas\RequisitionIssuanceFormSchema;
use App\Models\Requisition;
use App\Models\User;
use App\Services\RequisitionFulfillmentService;
use App\Services\RequisitionPurchaseRequestService;
use App\Support\RequisitionLineDisplay;
use App\Support\RequisitionStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
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

    public static function canReject(Requisition $record): bool
    {
        return false;
    }

    /**
     * True when at least one remaining line can be issued (has stock) or still needs
     * a first-time zero-stock acknowledgement (no issue remarks yet).
     */
    public static function hasActionableIssueLines(Requisition $record, bool $requireStock = false): bool
    {
        $record->loadMissing('items');
        $fulfillment = app(RequisitionFulfillmentService::class);
        $stockService = app(\App\Services\InventoryStockService::class);
        $officeId = (int) $record->office_id;

        foreach ($record->items as $line) {
            $remaining = $fulfillment->remainingQuantity($line);
            if ($remaining <= 0) {
                continue;
            }

            $stock = $officeId > 0
                ? max(0, $stockService->getStock((int) $line->item_id, $officeId))
                : 0;

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

    public static function acceptAndIssueAction(): Action
    {
        return Action::make('acceptAndIssue')
            ->label('Accept & issue')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Accept Requisition And Issue Stock')
            ->modalDescription(fn (Requisition $record): string|\Illuminate\Contracts\Support\Htmlable => RequisitionInfolistSchema::acceptIssueModalDescription($record))
            ->modalSubmitActionLabel('Yes, accept & issue')
            ->visible(fn (Requisition $record): bool => self::canAcceptAndIssue($record))
            ->fillForm(fn (Requisition $record): array => RequisitionIssuanceFormSchema::defaultFormState($record, remainderOnly: false))
            ->form(fn (Requisition $record): array => RequisitionIssuanceFormSchema::issueModalFields($record, remainderOnly: false))
            ->action(function (Requisition $record, array $data): void {
                self::runIssueAction($record, $data, 'Requisition accepted');
            });
    }

    public static function issueRemainderAction(): Action
    {
        return Action::make('issueRemainder')
            ->label('Issue remainder')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Issue Remainder From Requisition')
            ->modalDescription(fn (Requisition $record): string|\Illuminate\Contracts\Support\Htmlable => RequisitionInfolistSchema::acceptIssueModalDescription($record))
            ->modalSubmitActionLabel('Yes, issue remainder')
            ->visible(fn (Requisition $record): bool => self::canIssueRemainder($record))
            ->fillForm(fn (Requisition $record): array => RequisitionIssuanceFormSchema::defaultFormState($record, remainderOnly: true))
            ->form(fn (Requisition $record): array => RequisitionIssuanceFormSchema::issueModalFields($record, remainderOnly: true))
            ->action(function (Requisition $record, array $data): void {
                self::runIssueAction($record, $data, 'Stock issued');
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('custodianReject')
            ->label('Reject')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Reject this requisition?')
            ->modalDescription('The requestor will see your reason. No stock will be issued.')
            ->modalSubmitActionLabel('Yes, reject')
            ->form([
                Textarea::make('remarks')
                    ->label('Reason for rejection')
                    ->required()
                    ->rows(4)
                    ->placeholder('Explain why this requisition is being rejected.'),
            ])
            ->visible(fn (Requisition $record): bool => self::canReject($record))
            ->action(function (Requisition $record, array $data): void {
                $user = Auth::user();
                if (! $user instanceof User) {
                    return;
                }

                app(RequisitionFulfillmentService::class)->reject(
                    $record,
                    $user,
                    (string) ($data['remarks'] ?? ''),
                );

                Notification::make()
                    ->title('Requisition rejected')
                    ->danger()
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
    protected static function runIssueAction(Requisition $record, array $data, string $successTitle): void
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        $rows = collect($data['lines'] ?? [])
            ->filter(function (mixed $row): bool {
                if (! is_array($row)) {
                    return false;
                }

                if ((int) ($row['requisition_item_id'] ?? 0) <= 0) {
                    return false;
                }

                $qty = (int) ($row['quantity_to_issue'] ?? 0);

                return $qty > 0 || filled($row['issue_remarks'] ?? null);
            })
            ->unique(fn (array $row): int => (int) $row['requisition_item_id'])
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
