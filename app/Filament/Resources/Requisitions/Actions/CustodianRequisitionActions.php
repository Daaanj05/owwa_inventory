<?php

namespace App\Filament\Resources\Requisitions\Actions;

use App\Filament\Resources\Requisitions\Schemas\RequisitionInfolistSchema;
use App\Filament\Resources\Requisitions\Schemas\RequisitionIssuanceFormSchema;
use App\Models\Requisition;
use App\Models\User;
use App\Services\RequisitionFulfillmentService;
use App\Support\RequisitionLineDisplay;
use App\Support\RequisitionStatus;
use Filament\Actions\Action;
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

        return $record->isPendingCustodianReview();
    }

    public static function canIssueRemainder(Requisition $record): bool
    {
        if (! self::isCustodianReviewTarget($record)) {
            return false;
        }

        return $record->isAccepted() && $record->hasRemainingToIssue();
    }

    public static function canReject(Requisition $record): bool
    {
        if (! self::isCustodianReviewTarget($record)) {
            return false;
        }

        return $record->isPendingCustodianReview();
    }

    public static function acceptAndIssueAction(): Action
    {
        return Action::make('acceptAndIssue')
            ->label('Accept & issue')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->modalHeading('Accept requisition and issue stock')
            ->modalDescription(fn (Requisition $record): string|\Illuminate\Contracts\Support\Htmlable => RequisitionInfolistSchema::acceptIssueModalDescription($record))
            ->visible(fn (Requisition $record): bool => self::canAcceptAndIssue($record))
            ->fillForm(fn (Requisition $record): array => RequisitionIssuanceFormSchema::defaultFormState($record, remainderOnly: false))
            ->form(fn (Requisition $record): array => RequisitionIssuanceFormSchema::issueModalFields($record, remainderOnly: false))
            ->action(function (Requisition $record, array $data): void {
                self::runIssueAction($record, $data, 'Requisition accepted and stock issued');
            });
    }

    public static function issueRemainderAction(): Action
    {
        return Action::make('issueRemainder')
            ->label('Issue remainder')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('primary')
            ->modalHeading('Issue remainder from requisition')
            ->modalDescription(fn (Requisition $record): string|\Illuminate\Contracts\Support\Htmlable => RequisitionInfolistSchema::acceptIssueModalDescription($record))
            ->visible(fn (Requisition $record): bool => self::canIssueRemainder($record))
            ->fillForm(fn (Requisition $record): array => RequisitionIssuanceFormSchema::defaultFormState($record, remainderOnly: true))
            ->form(fn (Requisition $record): array => RequisitionIssuanceFormSchema::issueModalFields($record, remainderOnly: true))
            ->action(function (Requisition $record, array $data): void {
                self::runIssueAction($record, $data, 'Remainder issued');
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

                return (int) ($row['requisition_item_id'] ?? 0) > 0
                    && (int) ($row['quantity_to_issue'] ?? 0) > 0;
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
        $categoryCounts = (array) ($result['categories'] ?? []);

        if ($created > 0) {
            $record->refresh();

            Notification::make()
                ->title($successTitle)
                ->body(RequisitionLineDisplay::formatIssuanceCategorySummary($created, $categoryCounts).' Status: '.RequisitionStatus::label($record->status).'.')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('No stock was issued')
                ->body('Enter a quantity to issue for at least one line with available stock.')
                ->warning()
                ->send();
        }
    }
}
