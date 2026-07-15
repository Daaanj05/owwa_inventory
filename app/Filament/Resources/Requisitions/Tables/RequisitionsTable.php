<?php

namespace App\Filament\Resources\Requisitions\Tables;

use App\Filament\Resources\Requisitions\Actions\CustodianRequisitionActions;
use App\Filament\Resources\Requisitions\Actions\EmployeeRequisitionActions;
use App\Filament\Resources\Requisitions\Actions\RequisitionExportActions;
use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Filament\Resources\Requisitions\Schemas\RequisitionInfolistSchema;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaModalSchema;
use App\Filament\Support\OwwaTableDefaults;
use App\Models\Requisition;
use App\Models\User;
use App\Services\RequisitionCompileService;
use App\Support\EmployeeRequisitionFulfillment;
use App\Support\EmployeeRequisitionStatus;
use App\Support\OwwaReferenceLabels;
use App\Support\RequisitionLineFulfillmentState;
use App\Support\RequisitionStatus;
use App\Support\RequisitionViewPresenter;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class RequisitionsTable
{
    public static function configure(Table $table): Table
    {
        /** @var User|null $viewer */
        $viewer = Auth::user();
        $isEmployeeViewer = $viewer?->isEmployee() ?? false;

        $table = $table
            ->columns([
                TextColumn::make('transaction_number')
                    ->label(OwwaReferenceLabels::employeeRequisitionTransaction())
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium)
                    ->visible($isEmployeeViewer),
                TextColumn::make('compiledIntoRequisition.reference_code')
                    ->label(OwwaReferenceLabels::requisition())
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->visible($isEmployeeViewer),
                TextColumn::make('reference_code')
                    ->label(OwwaReferenceLabels::requisition())
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium)
                    ->visible(! $isEmployeeViewer),
                TextColumn::make('workflow_state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Requisition $record): string => self::workflowStateLabel($record))
                    ->color(fn (Requisition $record): string => self::workflowStateColor($record)),
                TextColumn::make('created_at')
                    ->label('Date filed')
                    ->date('M d, Y')
                    ->sortable(),
                TextColumn::make('requestedBy.name')
                    ->label('Requested by')
                    ->placeholder('—')
                    ->searchable()
                    ->visible(! $isEmployeeViewer),
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
                    ->options($isEmployeeViewer
                        ? RequisitionStatus::employeeFilterOptions()
                        : RequisitionStatus::filterOptions())
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
                    modelLabel: RequisitionResource::getModelLabel(),
                ),
                EmployeeRequisitionActions::tableActionGroup(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('compile')
                        ->label('Compile / Consolidate')
                        ->icon('heroicon-o-rectangle-stack')
                        ->color('primary')
                        ->deselectRecordsAfterCompletion()
                        ->visible(function () use ($table): bool {
                            $viewer = Auth::user();

                            if (! $viewer instanceof User || ! $viewer->isUnitConsolidator()) {
                                return false;
                            }

                            $livewire = $table->getLivewire();

                            return ($livewire->ucTab ?? 'received') === 'received';
                        })
                        ->action(function (Collection $records, BulkAction $action): void {
                            $compileService = app(RequisitionCompileService::class);
                            $eligible = $compileService->filterEligible($records);

                            if ($eligible->isEmpty()) {
                                Notification::make()
                                    ->title('Nothing to compile')
                                    ->body('Select reviewed (accepted) employee requisitions that have not been consolidated yet.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $officeIds = $eligible->pluck('office_id')->unique()->values();
                            $departmentIds = $eligible->pluck('department_id')->unique()->values();

                            if ($officeIds->count() !== 1 || $departmentIds->count() !== 1) {
                                Notification::make()
                                    ->title('Mixed office/department')
                                    ->body('Selected requests must share the same office and department.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            if ($eligible->count() !== $records->count()) {
                                Notification::make()
                                    ->title('Some rows skipped')
                                    ->body('Only reviewed employee requests that are not yet consolidated will be included.')
                                    ->warning()
                                    ->send();
                            }

                            $livewire = $action->getLivewire();

                            if (! $livewire instanceof ListRequisitions) {
                                return;
                            }

                            // replaceMountedAction clears any stuck create modal after backdrop/escape dismiss.
                            $livewire->replaceMountedAction('create', [
                                'office_id' => (int) $officeIds->first(),
                                'department_id' => (int) $departmentIds->first(),
                                'prefillSourceRequisitionIds' => $eligible->modelKeys(),
                            ], ['schemaComponent' => 'content']);
                        }),
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => ! $isEmployeeViewer),
                ]),
            ])
            ->recordUrl(null)
            ->recordAction('view');

        return OwwaTableDefaults::hideRedundantToolbarIcons($table);
    }

    protected static function workflowStateLabel(Requisition $record): string
    {
        if ($record->isEmployeeRequest()) {
            $status = EmployeeRequisitionStatus::label($record);
            $fulfillment = EmployeeRequisitionFulfillment::label($record);

            if (filled($fulfillment)) {
                return "{$status} · {$fulfillment}";
            }

            return $status;
        }

        $status = RequisitionStatus::label($record->status);
        $fulfillment = self::custodianFulfillmentLabel($record);

        if (filled($fulfillment)) {
            return "{$status} · {$fulfillment}";
        }

        return $status;
    }

    protected static function workflowStateColor(Requisition $record): string
    {
        if ($record->isEmployeeRequest()) {
            $fulfillment = EmployeeRequisitionFulfillment::label($record);

            if (filled($fulfillment)) {
                return EmployeeRequisitionFulfillment::color($record);
            }

            return EmployeeRequisitionStatus::color($record);
        }

        $record->loadMissing('items');

        if ($record->items->isEmpty()) {
            return RequisitionStatus::color($record->status);
        }

        if ($record->hasBackorderedLines()) {
            return RequisitionLineFulfillmentState::color(RequisitionLineFulfillmentState::BACKORDERED);
        }

        if ($record->items->contains(fn ($line): bool => $line->fulfillmentState() === RequisitionLineFulfillmentState::PARTIALLY_ISSUED)) {
            return RequisitionLineFulfillmentState::color(RequisitionLineFulfillmentState::PARTIALLY_ISSUED);
        }

        if ($record->items->every(fn ($line): bool => $line->fulfillmentState() === RequisitionLineFulfillmentState::FULLY_ISSUED)) {
            return RequisitionLineFulfillmentState::color(RequisitionLineFulfillmentState::FULLY_ISSUED);
        }

        return RequisitionStatus::color($record->status);
    }

    protected static function custodianFulfillmentLabel(Requisition $record): ?string
    {
        $record->loadMissing('items');

        if ($record->items->isEmpty()) {
            return null;
        }

        $states = $record->items
            ->map(fn ($line) => $line->fulfillmentState())
            ->unique()
            ->values();

        if ($states->contains(RequisitionLineFulfillmentState::BACKORDERED)) {
            return RequisitionLineFulfillmentState::label(RequisitionLineFulfillmentState::BACKORDERED);
        }

        if ($states->contains(RequisitionLineFulfillmentState::PARTIALLY_ISSUED)) {
            return RequisitionLineFulfillmentState::label(RequisitionLineFulfillmentState::PARTIALLY_ISSUED);
        }

        if ($states->every(fn (string $state): bool => $state === RequisitionLineFulfillmentState::FULLY_ISSUED)) {
            return RequisitionLineFulfillmentState::label(RequisitionLineFulfillmentState::FULLY_ISSUED);
        }

        return RequisitionLineFulfillmentState::label(RequisitionLineFulfillmentState::IN_STOCK);
    }
}
