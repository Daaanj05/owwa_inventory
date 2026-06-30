<?php

namespace App\Filament\Resources\Requisitions\Tables;

use App\Filament\Resources\Requisitions\Actions\CustodianRequisitionActions;
use App\Filament\Resources\Requisitions\Actions\RequisitionExportActions;
use App\Filament\Resources\Requisitions\Schemas\RequisitionInfolistSchema;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaModalSchema;
use App\Models\Distribution;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\RequisitionWorkflowNotificationService;
use App\Support\OwwaReferenceLabels;
use App\Support\RequisitionStatus;
use App\Support\RequisitionViewPresenter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class RequisitionsTable
{
    public static function configure(Table $table): Table
    {
        $isSupplyCustodian = Auth::user() instanceof User
            && Auth::user()->isSupplyCustodian();

        return $table
            ->columns([
                TextColumn::make('reference_code')
                    ->label(OwwaReferenceLabels::requisition())
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => RequisitionStatus::label($state))
                    ->color(fn (?string $state): string => RequisitionStatus::color($state)),
                TextColumn::make('created_at')
                    ->label('Date filed')
                    ->date('M d, Y')
                    ->sortable(),
                TextColumn::make('requestedBy.name')
                    ->label('Requested by')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('office.name')
                    ->label('Office')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('department.name')
                    ->label('Department')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('approved_at')
                    ->label('Actioned on')
                    ->date('M d, Y')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(RequisitionStatus::filterOptions())
                    ->placeholder('All statuses'),
                SelectFilter::make('department_id')
                    ->label('Department')
                    ->relationship(
                        'department',
                        'name',
                        fn ($query) => $query->active()
                    )
                    ->searchable()
                    ->preload()
                    ->placeholder('All departments'),
            ])
            ->emptyStateHeading('No requisitions yet')
            ->emptyStateDescription('Requisitions submitted by employees will appear here.')
            ->emptyStateIcon('heroicon-o-document-text')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    OwwaModalSchema::withHero(
                        fn (Requisition $record): array => RequisitionViewPresenter::forRecord($record),
                        RequisitionInfolistSchema::modalDetailSections(),
                    ),
                    [
                        RequisitionExportActions::exportRisAction(),
                        CustodianRequisitionActions::acceptAndIssueAction(),
                        CustodianRequisitionActions::issueRemainderAction(),
                        CustodianRequisitionActions::rejectAction(),
                        Action::make('approveFromView')
                            ->label('Approve')
                            ->icon('heroicon-o-check')
                            ->color('success')
                            ->requiresConfirmation()
                            ->modalHeading('Approve requisition')
                            ->modalDescription('This will mark the requisition as accepted.')
                            ->visible(function (Requisition $record): bool {
                                $user = Auth::user();

                                return $user instanceof User
                                    && $user->isUnitConsolidator()
                                    && $record->status === Requisition::STATUS_PENDING
                                    && $record->requestedBy?->role === User::ROLE_EMPLOYEE;
                            })
                            ->action(function (Requisition $record): void {
                                $record->update([
                                    'status' => Requisition::STATUS_ACCEPTED,
                                    'approved_by' => Auth::id(),
                                    'approved_at' => now(),
                                ]);
                                Notification::make()->title('Requisition accepted')->success()->send();
                            }),
                        Action::make('rejectFromView')
                            ->label('Reject')
                            ->icon('heroicon-o-x-mark')
                            ->color('danger')
                            ->requiresConfirmation()
                            ->modalHeading('Reject this requisition?')
                            ->modalDescription('Are you sure you want to reject this requisition? Please provide a reason below.')
                            ->modalSubmitActionLabel('Yes, reject')
                            ->form([
                                Textarea::make('remarks')
                                    ->label('Reason for rejection')
                                    ->required()
                                    ->rows(4)
                                    ->placeholder('Explain why this requisition is being rejected.'),
                            ])
                            ->visible(function (Requisition $record): bool {
                                $user = Auth::user();

                                return $user instanceof User
                                    && $user->isUnitConsolidator()
                                    && $record->status === Requisition::STATUS_PENDING
                                    && $record->requestedBy?->role === User::ROLE_EMPLOYEE;
                            })
                            ->action(function (Requisition $record, array $data): void {
                                $record->update([
                                    'status' => Requisition::STATUS_REJECTED,
                                    'remarks' => $data['remarks'] ?? null,
                                    'approved_by' => Auth::id(),
                                    'approved_at' => now(),
                                ]);
                                Notification::make()->title('Requisition rejected')->danger()->send();
                            }),
                    ],
                    '5xl',
                ),
                ActionGroup::make([
                    CustodianRequisitionActions::acceptAndIssueAction(),
                    CustodianRequisitionActions::issueRemainderAction(),
                    CustodianRequisitionActions::rejectAction(),
                    OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_WIDE)
                        ->visible(function (Requisition $record): bool {
                            $user = Auth::user();
                            if (! $user instanceof User) {
                                return false;
                            }

                            // Only the original requester can edit their own pending request.
                            return $record->status === Requisition::STATUS_PENDING
                                && (int) $record->requested_by === (int) $user->id;
                        }),
                    Action::make('approve')
                        ->label('Approve')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Approve requisition')
                        ->modalDescription('This will mark the requisition as accepted.')
                        ->visible(function (Requisition $record): bool {
                            $user = Auth::user();

                            return $user instanceof User
                                && $user->isUnitConsolidator()
                                && $record->status === Requisition::STATUS_PENDING
                                && $record->requestedBy?->role === User::ROLE_EMPLOYEE;
                        })
                        ->action(function (Requisition $record): void {
                            $record->update([
                                'status' => Requisition::STATUS_ACCEPTED,
                                'approved_by' => Auth::id(),
                                'approved_at' => now(),
                            ]);
                            Notification::make()->title('Requisition accepted')->success()->send();
                        }),
                    Action::make('reject')
                        ->label('Reject')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Reject this requisition?')
                        ->modalDescription('Are you sure you want to reject this requisition? Please provide a reason below.')
                        ->modalSubmitActionLabel('Yes, reject')
                        ->form([
                            Textarea::make('remarks')
                                ->label('Reason for rejection')
                                ->required()
                                ->rows(4)
                                ->placeholder('Explain why this requisition is being rejected.'),
                        ])
                        ->visible(function (Requisition $record): bool {
                            $user = Auth::user();

                            return $user instanceof User
                                && $user->isUnitConsolidator()
                                && $record->status === Requisition::STATUS_PENDING
                                && $record->requestedBy?->role === User::ROLE_EMPLOYEE;
                        })
                        ->action(function (Requisition $record, array $data): void {
                            $record->update([
                                'status' => Requisition::STATUS_REJECTED,
                                'remarks' => $data['remarks'] ?? null,
                                'approved_by' => Auth::id(),
                                'approved_at' => now(),
                            ]);
                            Notification::make()->title('Requisition rejected')->danger()->send();
                        }),
                    Action::make('distribute')
                        ->label('Distribute items')
                        ->icon('heroicon-o-gift')
                        ->color('success')
                        ->modalHeading('Distribute request items')
                        ->modalDescription(fn (Requisition $record): string => 'Distribute items from request '.$record->reference_code.' to '.$record->requestedBy?->name.'.')
                        ->visible(function (Requisition $record): bool {
                            $user = Auth::user();

                            return $user instanceof User
                                && $user->isUnitConsolidator()
                                && $record->status === Requisition::STATUS_ACCEPTED
                                && $record->requestedBy?->role === User::ROLE_EMPLOYEE;
                        })
                        ->form(function (Requisition $record): array {
                            $record->load('items.item');
                            $defaultItems = $record->items->map(fn (RequisitionItem $ri): array => [
                                'item_id' => $ri->item_id,
                                'item_label' => $ri->item?->name ?? "Item #{$ri->item_id}",
                                'quantity' => $ri->quantity,
                            ])->toArray();

                            return [
                                Select::make('distributed_to')
                                    ->label('Distribute to')
                                    ->default($record->requested_by)
                                    ->options(function () {
                                        $user = Auth::user();
                                        $query = User::query()->where('role', User::ROLE_EMPLOYEE);
                                        if ($user instanceof User && $user->office_id) {
                                            $query->where('office_id', $user->office_id);
                                        }

                                        return $query->orderBy('name')->pluck('name', 'id');
                                    })
                                    ->required()
                                    ->searchable(),
                                Repeater::make('items')
                                    ->label('Items to distribute')
                                    ->schema([
                                        Select::make('item_id')
                                            ->label('Item')
                                            ->relationship('item', 'name')
                                            ->disabled()
                                            ->required(),
                                        TextInput::make('quantity')
                                            ->label('Quantity')
                                            ->numeric()
                                            ->minValue(1)
                                            ->required(),
                                    ])
                                    ->columns(2)
                                    ->default($defaultItems)
                                    ->addable(false)
                                    ->deletable(true),
                            ];
                        })
                        ->action(function (Requisition $record, array $data): void {
                            $user = Auth::user();
                            if (! $user instanceof User) {
                                return;
                            }

                            $items = $data['items'] ?? [];
                            $created = 0;

                            foreach ($items as $row) {
                                if (empty($row['item_id']) || empty($row['quantity'])) {
                                    continue;
                                }

                                Distribution::create([
                                    'office_id' => $user->office_id ?? $record->office_id,
                                    'department_id' => $user->department_id ?? $record->department_id,
                                    'requisition_id' => $record->id,
                                    'item_id' => (int) $row['item_id'],
                                    'quantity' => (int) $row['quantity'],
                                    'distributed_to' => (int) $data['distributed_to'],
                                    'distributed_by' => $user->id,
                                    'distribution_date' => now()->toDateString(),
                                ]);
                                $created++;
                            }

                            if ($created > 0) {
                                Notification::make()
                                    ->title('Items distributed')
                                    ->body("Created {$created} distribution record(s).")
                                    ->success()
                                    ->send();

                                $employee = User::query()->find((int) $data['distributed_to']);

                                if ($employee instanceof User) {
                                    app(RequisitionWorkflowNotificationService::class)->handleDistributed(
                                        $employee,
                                        $record->fresh(),
                                    );
                                }
                            } else {
                                Notification::make()
                                    ->title('No items were distributed')
                                    ->warning()
                                    ->send();
                            }
                        }),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->recordUrl(null)
            ->recordAction('view');
    }
}
