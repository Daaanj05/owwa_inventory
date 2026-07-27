<?php

namespace App\Filament\Resources\PropertyActionRequests\Actions;

use App\Filament\Concerns\SwitchesUcSentTab;
use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Filament\Resources\Transfers\TransferResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\PropertyActionRequest;
use App\Models\User;
use App\Services\PropertyActionRequestCompileService;
use App\Services\PropertyActionRequestWorkflowService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;

class PropertyActionRequestTableActions
{
    /**
     * @return array<int, Action>
     */
    public static function recordActions(): array
    {
        return [
            OwwaFormModalDefaults::editActionForResource(PropertyActionRequestResource::class, OwwaFormModalDefaults::WIDTH_MEDIUM)
                ->visible(fn (PropertyActionRequest $record): bool => ($user = Filament::auth()->user()) instanceof User
                    && $user->isUnitConsolidator()
                    && $record->status === PropertyActionRequest::STATUS_PENDING_SC),
            self::ucApproveAction(),
            self::ucRejectAction(),
            self::ucCompileAndSendAction(),
            self::scApproveAction(),
            self::scRejectAction(),
            self::scReceiveAndRouteAction(),
            self::openDisposalAction(),
            self::openTransferAction(),
        ];
    }

    public static function ucApproveAction(): Action
    {
        return Action::make('ucApprove')
            ->label('Approve')
            ->icon('heroicon-o-check')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (PropertyActionRequest $record): bool => ($user = Filament::auth()->user()) instanceof User
                && $user->isUnitConsolidator()
                && $record->status === PropertyActionRequest::STATUS_PENDING_UC
                && $record->uc_approved_at === null
                && $record->compiled_into_property_action_request_id === null)
            ->action(function (PropertyActionRequest $record, Action $action): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                app(PropertyActionRequestWorkflowService::class)->approveByUnitConsolidator($record, $user);
                Notification::make()->title('Property return approved — ready to compile')->success()->send();
            });
    }

    public static function ucRejectAction(): Action
    {
        return Action::make('ucReject')
            ->label('Reject')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->schema([
                Textarea::make('remarks')
                    ->label('Remarks')
                    ->rows(2),
            ])
            ->visible(fn (PropertyActionRequest $record): bool => ($user = Filament::auth()->user()) instanceof User
                && $user->isUnitConsolidator()
                && $record->status === PropertyActionRequest::STATUS_PENDING_UC
                && $record->compiled_into_property_action_request_id === null)
            ->action(function (PropertyActionRequest $record, array $data): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                app(PropertyActionRequestWorkflowService::class)->rejectByUnitConsolidator(
                    $record,
                    $user,
                    $data['remarks'] ?? null,
                );
                Notification::make()->title('Request rejected')->success()->send();
            });
    }

    public static function ucCompileAndSendAction(): Action
    {
        return Action::make('ucCompileAndSend')
            ->label('Compile & send to SC')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Compile & send to Supply Custodian')
            ->modalDescription('This creates one UC property-return batch from this approved employee return and submits it to SC.')
            ->schema([
                Textarea::make('remarks')
                    ->label('Remarks (optional)')
                    ->rows(2),
            ])
            ->visible(fn (PropertyActionRequest $record): bool => ($user = Filament::auth()->user()) instanceof User
                && $user->isUnitConsolidator()
                && $record->isUcApprovedForCompile())
            ->action(function (PropertyActionRequest $record, Action $action, array $data): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                try {
                    app(PropertyActionRequestCompileService::class)->createCompiledSubmission(
                        $user,
                        [$record],
                        $data['remarks'] ?? null,
                    );
                } catch (\InvalidArgumentException $exception) {
                    Notification::make()
                        ->title('Unable to compile')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()->title('Compiled and sent to SC')->success()->send();
                SwitchesUcSentTab::switchLivewireUcTabToSent($action->getLivewire());
            });
    }

    public static function ucApproveFromViewAction(): Action
    {
        return Action::make('approveFromView')
            ->label('Approve')
            ->icon('heroicon-o-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve property return')
            ->modalDescription('Approve this employee return so it can be compiled into a UC submission to the Supply Custodian.')
            ->visible(function (PropertyActionRequest $record): bool {
                $user = Filament::auth()->user();

                return $user instanceof User
                    && $user->isUnitConsolidator()
                    && $record->status === PropertyActionRequest::STATUS_PENDING_UC
                    && $record->uc_approved_at === null
                    && $record->compiled_into_property_action_request_id === null;
            })
            ->action(function (PropertyActionRequest $record, Action $action): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                app(PropertyActionRequestWorkflowService::class)->approveByUnitConsolidator($record, $user);
                Notification::make()->title('Property return approved — ready to compile')->success()->send();
            });
    }

    public static function ucCompileFromViewAction(): Action
    {
        return Action::make('compileFromView')
            ->label('Compile & send to SC')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Compile & send to Supply Custodian')
            ->modalDescription('This creates one UC property-return batch and submits it to SC.')
            ->schema([
                Textarea::make('remarks')
                    ->label('Remarks (optional)')
                    ->rows(2),
            ])
            ->visible(fn (PropertyActionRequest $record): bool => ($user = Filament::auth()->user()) instanceof User
                && $user->isUnitConsolidator()
                && $record->isUcApprovedForCompile())
            ->action(function (PropertyActionRequest $record, Action $action, array $data): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                try {
                    app(PropertyActionRequestCompileService::class)->createCompiledSubmission(
                        $user,
                        [$record],
                        $data['remarks'] ?? null,
                    );
                } catch (\InvalidArgumentException $exception) {
                    Notification::make()
                        ->title('Unable to compile')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()->title('Compiled and sent to SC')->success()->send();
                SwitchesUcSentTab::switchLivewireUcTabToSent($action->getLivewire());
            });
    }

    public static function ucRejectFromViewAction(): Action
    {
        return Action::make('rejectFromView')
            ->label('Reject')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Reject this property return?')
            ->modalDescription('Are you sure you want to reject this property return? Please provide a reason below.')
            ->modalSubmitActionLabel('Yes, reject')
            ->form([
                Textarea::make('remarks')
                    ->label('Reason for rejection')
                    ->required()
                    ->rows(4)
                    ->placeholder('Explain why this property return is being rejected.'),
            ])
            ->visible(function (PropertyActionRequest $record): bool {
                $user = Filament::auth()->user();

                return $user instanceof User
                    && $user->isUnitConsolidator()
                    && $record->status === PropertyActionRequest::STATUS_PENDING_UC
                    && $record->compiled_into_property_action_request_id === null;
            })
            ->action(function (PropertyActionRequest $record, array $data): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                app(PropertyActionRequestWorkflowService::class)->rejectByUnitConsolidator(
                    $record,
                    $user,
                    $data['remarks'] ?? null,
                );
                Notification::make()->title('Request rejected')->success()->send();
            });
    }

    public static function scApproveAction(): Action
    {
        return Action::make('scApprove')
            ->label('Approve')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve property return')
            ->modalDescription('Approve in the system while the item is still in transit. You will Receive & route after the physical item arrives.')
            ->visible(fn (PropertyActionRequest $record): bool => ($user = Filament::auth()->user()) instanceof User
                && $user->isSupplyCustodian()
                && $record->status === PropertyActionRequest::STATUS_PENDING_SC)
            ->action(function (PropertyActionRequest $record): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                app(PropertyActionRequestWorkflowService::class)->approveBySupplyCustodian($record, $user);
                Notification::make()
                    ->title('Approved — awaiting physical item')
                    ->success()
                    ->send();
            });
    }

    public static function scRejectAction(): Action
    {
        return Action::make('scReject')
            ->label('SC reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->schema([
                Textarea::make('remarks')
                    ->label('Remarks')
                    ->rows(2),
            ])
            ->visible(fn (PropertyActionRequest $record): bool => ($user = Filament::auth()->user()) instanceof User
                && $user->isSupplyCustodian()
                && $record->status === PropertyActionRequest::STATUS_PENDING_SC)
            ->action(function (PropertyActionRequest $record, array $data): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                app(PropertyActionRequestWorkflowService::class)->rejectBySupplyCustodian(
                    $record,
                    $user,
                    $data['remarks'] ?? null,
                );
                Notification::make()->title('Request rejected')->success()->send();
            });
    }

    public static function scReceiveAndRouteAction(): Action
    {
        return Action::make('scReceiveAndRoute')
            ->label('Receive & route')
            ->icon('heroicon-o-inbox-arrow-down')
            ->color('primary')
            ->modalHeading('Receive item and route outcome')
            ->modalDescription('Confirm physical receipt, then choose Dispose, Transfer, or Return to stock.')
            ->schema([
                Select::make('outcome')
                    ->label('Outcome')
                    ->options([
                        PropertyActionRequest::OUTCOME_DISPOSE => 'Dispose',
                        PropertyActionRequest::OUTCOME_TRANSFER => 'Transfer',
                        PropertyActionRequest::OUTCOME_RETURN_TO_STOCK => 'Return to stock',
                    ])
                    ->required()
                    ->live()
                    ->default(fn (PropertyActionRequest $record): string => $record->suggestedReceiveOutcome()),
                TextInput::make('new_estimated_useful_life')
                    ->label('New estimated useful life (months)')
                    ->placeholder('e.g. 36')
                    ->helperText('Optional. Resets catalog EUL for reissue after return to stock. Enter months; labels show months and years.')
                    ->visible(function (Get $get, PropertyActionRequest $record): bool {
                        if ($get('outcome') !== PropertyActionRequest::OUTCOME_RETURN_TO_STOCK) {
                            return false;
                        }

                        $record->loadMissing('lines.issuance.item.category');

                        return $record->lines->contains(function ($line): bool {
                            $slug = $line->issuance?->item?->category?->getTemplateSlug();

                            return $slug === 'semi_expendable';
                        });
                    }),
                Textarea::make('receipt_remarks')
                    ->label('Receipt remarks')
                    ->rows(2),
            ])
            ->visible(fn (PropertyActionRequest $record): bool => ($user = Filament::auth()->user()) instanceof User
                && $user->isSupplyCustodian()
                && $record->status === PropertyActionRequest::STATUS_APPROVED)
            ->action(function (PropertyActionRequest $record, array $data): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                app(PropertyActionRequestWorkflowService::class)->receiveAndRoute(
                    $record,
                    $user,
                    (string) ($data['outcome'] ?? $record->suggestedReceiveOutcome()),
                    isset($data['new_estimated_useful_life']) ? (string) $data['new_estimated_useful_life'] : null,
                    isset($data['receipt_remarks']) ? (string) $data['receipt_remarks'] : null,
                );
                Notification::make()->title('Item received and routed')->success()->send();
            });
    }

    /** @deprecated Use scReceiveAndRouteAction() */
    public static function scExecuteAction(): Action
    {
        return self::scReceiveAndRouteAction();
    }

    public static function openDisposalAction(): Action
    {
        return Action::make('openDisposal')
            ->label('Open Disposal')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->url(function (PropertyActionRequest $record): ?string {
                $disposalId = $record->linkedDisposalId();

                return $disposalId
                    ? DisposalResource::getUrl('view', ['record' => $disposalId])
                    : null;
            })
            ->visible(fn (PropertyActionRequest $record): bool => $record->status === PropertyActionRequest::STATUS_EXECUTED
                && $record->linkedDisposalId() !== null)
            ->openUrlInNewTab();
    }

    public static function openTransferAction(): Action
    {
        return Action::make('openTransfer')
            ->label('Open Transfer')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->url(function (PropertyActionRequest $record): ?string {
                $transferId = $record->linkedTransferId();

                return $transferId
                    ? TransferResource::getUrl('view', ['record' => $transferId])
                    : null;
            })
            ->visible(fn (PropertyActionRequest $record): bool => $record->status === PropertyActionRequest::STATUS_EXECUTED
                && $record->linkedTransferId() !== null)
            ->openUrlInNewTab();
    }
}
