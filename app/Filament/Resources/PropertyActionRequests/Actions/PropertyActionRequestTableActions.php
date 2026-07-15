<?php

namespace App\Filament\Resources\PropertyActionRequests\Actions;

use App\Filament\Concerns\SwitchesUcSentTab;
use App\Filament\Resources\PropertyActionRequests\PropertyActionRequestResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\PropertyActionRequest;
use App\Models\User;
use App\Services\PropertyActionRequestWorkflowService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

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
            self::scApproveAction(),
            self::scRejectAction(),
            self::scExecuteAction(),
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
                && $record->status === PropertyActionRequest::STATUS_PENDING_UC)
            ->action(function (PropertyActionRequest $record, Action $action): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                app(PropertyActionRequestWorkflowService::class)->approveByUnitConsolidator($record, $user);
                Notification::make()->title('Request endorsed to SC')->success()->send();
                SwitchesUcSentTab::switchLivewireUcTabToSent($action->getLivewire());
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
                && $record->status === PropertyActionRequest::STATUS_PENDING_UC)
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

    public static function ucApproveFromViewAction(): Action
    {
        return Action::make('approveFromView')
            ->label('Approve')
            ->icon('heroicon-o-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve property return')
            ->modalDescription('This will endorse the request to the Supply Custodian.')
            ->visible(function (PropertyActionRequest $record): bool {
                $user = Filament::auth()->user();

                return $user instanceof User
                    && $user->isUnitConsolidator()
                    && $record->status === PropertyActionRequest::STATUS_PENDING_UC;
            })
            ->action(function (PropertyActionRequest $record, Action $action): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                app(PropertyActionRequestWorkflowService::class)->approveByUnitConsolidator($record, $user);
                Notification::make()->title('Request endorsed to SC')->success()->send();
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
                    && $record->status === PropertyActionRequest::STATUS_PENDING_UC;
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
            ->label('SC approve')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Approve this property return in the system.')
            ->visible(fn (PropertyActionRequest $record): bool => ($user = Filament::auth()->user()) instanceof User
                && $user->isSupplyCustodian()
                && $record->status === PropertyActionRequest::STATUS_PENDING_SC)
            ->action(function (PropertyActionRequest $record): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                app(PropertyActionRequestWorkflowService::class)->approveBySupplyCustodian($record, $user);
                Notification::make()->title('Request approved')->success()->send();
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

    public static function scExecuteAction(): Action
    {
        return Action::make('scExecute')
            ->label('Execute')
            ->icon('heroicon-o-play')
            ->color('primary')
            ->requiresConfirmation()
            ->visible(fn (PropertyActionRequest $record): bool => ($user = Filament::auth()->user()) instanceof User
                && $user->isSupplyCustodian()
                && $record->status === PropertyActionRequest::STATUS_APPROVED)
            ->action(function (PropertyActionRequest $record): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                app(PropertyActionRequestWorkflowService::class)->execute($record, $user);
                Notification::make()->title('Request executed')->success()->send();
            });
    }
}
