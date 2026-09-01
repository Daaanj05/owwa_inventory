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
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
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
        $isUnitConsolidatorViewer = $viewer?->isUnitConsolidator() ?? false;

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
                    ->visible(function () use ($table, $isEmployeeViewer, $isUnitConsolidatorViewer): bool {
                        if ($isEmployeeViewer) {
                            return false;
                        }

                        if (! $isUnitConsolidatorViewer) {
                            return true;
                        }

                        $livewire = $table->getLivewire();

                        return ! $livewire instanceof ListRequisitions
                            || ($livewire->ucTab ?? 'received') !== 'sent';
                    }),
                TextColumn::make('office.name')
                    ->label('Office')
                    ->searchable()
                    ->placeholder('—')
                    ->visible(! $isEmployeeViewer),
                TextColumn::make('department.name')
                    ->label('Department')
                    ->searchable()
                    ->placeholder('—')
                    ->visible(! $isEmployeeViewer),
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
                    ->placeholder('All departments')
                    ->visible(! $isEmployeeViewer),
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
                        EmployeeRequisitionActions::submitFromViewAction(),
                        RequisitionExportActions::exportRisAction(),
                        RequisitionExportActions::exportRisPdfAction(),
                        CustodianRequisitionActions::createPurchaseRequestAction(),
                        CustodianRequisitionActions::reviewAndIssueAction(),
                        CustodianRequisitionActions::issueRemainderAction(),
                        Action::make('reviewFromView')
                            ->label('Mark as reviewed')
                            ->icon('heroicon-o-check')
                            ->color('success')
                            ->requiresConfirmation()
                            ->modalHeading('Mark employee requisition as reviewed')
                            ->modalDescription('This confirms the Unit Consolidator has reviewed the request. You can endorse quantities when compiling to Supply Custodian.')
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
                                Notification::make()->title('Requisition reviewed')->success()->send();
                            }),
                        self::submitToScAction($table),
                    ],
                    '5xl',
                    modelLabel: RequisitionResource::getModelLabel(),
                ),
                ActionGroup::make([
                    self::submitToScAction($table),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray')
                    ->visible(function (Requisition $record) use ($table): bool {
                        return self::canSubmitReviewedEmployeeRequisitionToSc($record, $table);
                    }),
                EmployeeRequisitionActions::tableActionGroup(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('compile')
                        ->label('Compile / Consolidate')
                        ->icon('heroicon-o-rectangle-stack')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading('Compile selected requisitions?')
                        ->modalDescription('Eligible reviewed employee requests will be used to start a new consolidated requisition to Supply Custodian.')
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
                ]),
            ])
            ->selectable(function () use ($isUnitConsolidatorViewer): bool {
                if ($isUnitConsolidatorViewer) {
                    return true;
                }

                $viewer = Auth::user();

                // Supply Custodian keeps row selection for Export RIS only (no bulk toolbar actions).
                return $viewer instanceof User && $viewer->isSupplyCustodian();
            })
            ->recordUrl(null)
            ->recordAction('view');

        return OwwaTableDefaults::hideRedundantToolbarIcons($table);
    }

    protected static function submitToScAction(Table $table): Action
    {
        return Action::make('submitToSc')
            ->label('Submit to SC')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Submit to Supply Custodian?')
            ->modalDescription('This will start a consolidated requisition using the selected employee request.')
            ->visible(fn (Requisition $record): bool => self::canSubmitReviewedEmployeeRequisitionToSc($record, $table))
            ->action(function (Requisition $record, Action $action): void {
                $livewire = $action->getLivewire();

                if (! $livewire instanceof ListRequisitions) {
                    return;
                }

                $livewire->replaceMountedAction('create', [
                    'office_id' => (int) $record->office_id,
                    'department_id' => (int) $record->department_id,
                    'prefillSourceRequisitionIds' => [$record->getKey()],
                ], ['schemaComponent' => 'content']);
            });
    }

    protected static function canSubmitReviewedEmployeeRequisitionToSc(Requisition $record, Table $table): bool
    {
        $viewer = Auth::user();
        $livewire = $table->getLivewire();

        return $viewer instanceof User
            && $viewer->isUnitConsolidator()
            && $livewire instanceof ListRequisitions
            && ($livewire->ucTab ?? 'received') === 'received'
            && $record->status === Requisition::STATUS_ACCEPTED
            && $record->compiled_into_requisition_id === null
            && $record->requestedBy?->role === User::ROLE_EMPLOYEE;
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
