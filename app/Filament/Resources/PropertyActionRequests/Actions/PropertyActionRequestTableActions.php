<?php

namespace App\Filament\Resources\PropertyActionRequests\Actions;

use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Resources\PropertyActionRequests\Pages\ListPropertyActionRequests;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\InventoryUnit;
use App\Models\PropertyActionRequest;
use App\Models\User;
use App\Services\PropertyActionRequestWorkflowService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use InvalidArgumentException;
use RuntimeException;

class PropertyActionRequestTableActions
{
    private const REPAIR_RETURN_TO_STOCK = 'return_to_stock';

    private const REPAIR_REISSUE = 'reissue';

    private const REPAIR_DISPOSE = 'dispose';

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

    public static function scForTransferAction(): Action
    {
        return Action::make('scForTransfer')
            ->label('For transfer')
            ->icon('heroicon-o-arrows-right-left')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Send to Property Transfer Report?')
            ->modalDescription('Opens a PTR draft. Complete the form, export, then Mark as approved on the transfer page.')
            ->visible(fn (PropertyActionRequest $record): bool => ($user = Filament::auth()->user()) instanceof User
                && $user->isSupplyCustodian()
                && $record->isPendingDecision())
            ->action(function (PropertyActionRequest $record, Action $action): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                try {
                    $transfer = app(\App\Services\PropertyReturnDecisionService::class)
                        ->decideForTransfer($record, $user);
                } catch (InvalidArgumentException|RuntimeException $exception) {
                    Notification::make()->title('Cannot open transfer')->body($exception->getMessage())->danger()->send();

                    return;
                }

                $transfer->loadMissing('item');
                $url = TransferResource::viewModalUrl($transfer->id, array_filter([
                    'category' => $transfer->item?->item_category_id,
                ]));

                Notification::make()->title('PTR draft opened')->success()->send();
                $action->getLivewire()->redirect($url);
            });
    }

    public static function scForDisposalAction(): Action
    {
        return Action::make('scForDisposal')
            ->label('For disposal')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Send to disposal report?')
            ->modalDescription('Opens an IIRUP or IIRUSP draft. Complete the form, export, then Mark as approved on the disposal page.')
            ->visible(fn (PropertyActionRequest $record): bool => ($user = Filament::auth()->user()) instanceof User
                && $user->isSupplyCustodian()
                && $record->isPendingDecision())
            ->action(function (PropertyActionRequest $record, Action $action): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                try {
                    $disposal = app(\App\Services\PropertyReturnDecisionService::class)
                        ->decideForDisposal($record, $user);
                } catch (InvalidArgumentException|RuntimeException $exception) {
                    Notification::make()->title('Cannot open disposal')->body($exception->getMessage())->danger()->send();

                    return;
                }

                $disposal->loadMissing('item');
                $url = DisposalResource::viewModalUrl($disposal->id, array_filter([
                    'category' => $disposal->item?->item_category_id,
                ]));

                Notification::make()->title('Disposal draft opened')->success()->send();
                $action->getLivewire()->redirect($url);
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
                && $record->isPendingDecision())
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

    public static function openDisposalAction(): Action
    {
        return Action::make('openDisposal')
            ->label('Open Disposal')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->url(function (PropertyActionRequest $record): ?string {
                $disposalId = $record->linkedDisposalId();

                if ($disposalId === null) {
                    return null;
                }

                $disposal = \App\Models\Disposal::query()->with('item')->find($disposalId);

                return DisposalResource::viewModalUrl($disposalId, array_filter([
                    'category' => $disposal?->item?->item_category_id,
                ]));
            })
            ->visible(fn (PropertyActionRequest $record): bool => in_array($record->status, [
                PropertyActionRequest::STATUS_PENDING_DISPOSAL,
                PropertyActionRequest::STATUS_EXECUTED,
            ], true) && $record->linkedDisposalId() !== null);
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
            ->visible(fn (PropertyActionRequest $record): bool => in_array($record->status, [
                PropertyActionRequest::STATUS_PENDING_TRANSFER,
                PropertyActionRequest::STATUS_EXECUTED,
            ], true) && $record->linkedTransferId() !== null);
    }
}
