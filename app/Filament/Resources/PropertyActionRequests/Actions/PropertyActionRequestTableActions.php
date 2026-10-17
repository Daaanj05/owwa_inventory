<?php

namespace App\Filament\Resources\PropertyActionRequests\Actions;

use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Resources\PropertyActionRequests\Pages\ListPropertyActionRequests;
use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Filament\Resources\Transfers\TransferResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\InventoryUnit;
use App\Models\PropertyActionRequest;
use App\Models\User;
use App\Services\PropertyActionRequestWorkflowService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use InvalidArgumentException;
use RuntimeException;

class PropertyActionRequestTableActions
{
    private const REPAIR_RETURN_TO_STOCK = 'return_to_stock';

    private const REPAIR_REISSUE = 'reissue';

    private const REPAIR_DISPOSE = 'dispose';

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
            ->requiresConfirmation()
            ->modalHeading('Reject this property return?')
            ->modalDescription('The requestor will see your remarks. This cannot be undone from the Active list.')
            ->modalSubmitActionLabel('Yes, reject')
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
            ->visible(fn (PropertyActionRequest $record): bool => ($user = Filament::auth()->user()) instanceof User
                && $user->isUnitConsolidator()
                && $record->isUcApprovedForCompile())
            ->action(function (PropertyActionRequest $record, Action $action): void {
                $livewire = $action->getLivewire();

                if (! $livewire instanceof ListPropertyActionRequests) {
                    return;
                }

                $livewire->replaceMountedAction('create', [
                    'office_id' => (int) $record->office_id,
                    'department_id' => (int) $record->department_id,
                    'prefillSourcePropertyActionRequestIds' => [$record->id],
                ]);
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
            ->visible(fn (PropertyActionRequest $record): bool => ($user = Filament::auth()->user()) instanceof User
                && $user->isUnitConsolidator()
                && $record->isUcApprovedForCompile())
            ->action(function (PropertyActionRequest $record, Action $action): void {
                $livewire = $action->getLivewire();

                if ($livewire instanceof ListPropertyActionRequests) {
                    $livewire->replaceMountedAction('create', [
                        'office_id' => (int) $record->office_id,
                        'department_id' => (int) $record->department_id,
                        'prefillSourcePropertyActionRequestIds' => [$record->id],
                    ]);

                    return;
                }

                Notification::make()
                    ->title('Open Property Returns to compile')
                    ->body('Compile this return from the Property Returns list.')
                    ->warning()
                    ->send();
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
            ->modalDescription('Confirm physical receipt, then choose Return to stock (serviceable), Under repair (held at supply, not issuable), or Dispose (unserviceable).')
            ->schema([
                Select::make('outcome')
                    ->label('Outcome')
                    ->options(PropertyActionRequest::receiveOutcomeOptions())
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

                try {
                    app(PropertyActionRequestWorkflowService::class)->receiveAndRoute(
                        $record,
                        $user,
                        (string) ($data['outcome'] ?? $record->suggestedReceiveOutcome()),
                        isset($data['new_estimated_useful_life']) ? (string) $data['new_estimated_useful_life'] : null,
                        isset($data['receipt_remarks']) ? (string) $data['receipt_remarks'] : null,
                    );
                } catch (InvalidArgumentException|RuntimeException $exception) {
                    Notification::make()->title('Cannot route this return')->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Item received and routed')->success()->send();
            });
    }

    public static function scResolveRepairAction(): Action
    {
        return Action::make('scResolveRepair')
            ->label('Resolve repair')
            ->icon('heroicon-o-wrench-screwdriver')
            ->color('warning')
            ->modalHeading('Resolve unit under repair')
            ->modalDescription('Repaired units return to supply stock or go back to the original employee on a new ICS/PAR. Unrepairable units are disposed.')
            ->schema([
                Select::make('inventory_unit_id')
                    ->label('Unit under repair')
                    ->options(fn (PropertyActionRequest $record): array => $record->underRepairUnitsQuery()
                        ->with('item')
                        ->get()
                        ->mapWithKeys(fn (InventoryUnit $unit): array => [
                            $unit->id => trim(($unit->item?->name ?? 'Item').' — '.$unit->property_number),
                        ])
                        ->all())
                    ->default(fn (PropertyActionRequest $record): ?int => $record->underRepairUnitsQuery()->value('id'))
                    ->required(),
                Radio::make('resolution')
                    ->label('Result')
                    ->options([
                        self::REPAIR_RETURN_TO_STOCK => 'Repaired — return to supply stock',
                        self::REPAIR_REISSUE => 'Repaired — re-issue to original employee (new ICS/PAR)',
                        self::REPAIR_DISPOSE => 'Unrepairable — dispose',
                    ])
                    ->default(self::REPAIR_RETURN_TO_STOCK)
                    ->required()
                    ->live(),
                Textarea::make('disposal_remarks')
                    ->label('Disposal remarks')
                    ->rows(2)
                    ->visible(fn (Get $get): bool => $get('resolution') === self::REPAIR_DISPOSE),
            ])
            ->visible(fn (PropertyActionRequest $record): bool => ($user = Filament::auth()->user()) instanceof User
                && $user->isSupplyCustodian()
                && $record->status === PropertyActionRequest::STATUS_EXECUTED
                && $record->underRepairUnitsQuery()->exists())
            ->action(function (PropertyActionRequest $record, array $data): void {
                $user = Filament::auth()->user();
                $unit = $record->underRepairUnitsQuery()->whereKey((int) ($data['inventory_unit_id'] ?? 0))->first();

                if (! $user instanceof User || ! $unit instanceof InventoryUnit) {
                    Notification::make()->title('Unit is no longer under repair')->danger()->send();

                    return;
                }

                $workflow = app(PropertyActionRequestWorkflowService::class);

                try {
                    $resolution = $data['resolution'] ?? self::REPAIR_RETURN_TO_STOCK;

                    if ($resolution === self::REPAIR_REISSUE) {
                        $title = 'Re-issued as '.$workflow->reissueRepairedUnit($unit, $user)->controlNumber();
                    } elseif ($resolution === self::REPAIR_DISPOSE) {
                        $workflow->disposeUnrepairableUnit(
                            $unit,
                            $user,
                            isset($data['disposal_remarks']) ? (string) $data['disposal_remarks'] : null,
                        );
                        $title = 'Unit disposed';
                    } else {
                        $workflow->markRepairedToStock($unit, $user);
                        $title = 'Unit returned to supply stock';
                    }
                } catch (InvalidArgumentException|RuntimeException $exception) {
                    Notification::make()->title('Cannot resolve repair')->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title($title)->success()->send();
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

                if ($transferId === null) {
                    return null;
                }

                $transfer = \App\Models\Transfer::query()->with('item')->find($transferId);
                $categoryId = $transfer?->item?->item_category_id;

                return TransferResource::viewModalUrl($transferId, array_filter([
                    'category' => $categoryId,
                ]));
            })
            ->visible(fn (PropertyActionRequest $record): bool => $record->status === PropertyActionRequest::STATUS_EXECUTED
                && $record->linkedTransferId() !== null)
            ->openUrlInNewTab();
    }
}
