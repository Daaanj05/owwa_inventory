<?php

namespace App\Filament\Resources\Requisitions\Tables;

use App\Filament\Resources\Requisitions\Actions\CustodianRequisitionActions;
use App\Filament\Resources\Requisitions\Actions\RequisitionExportActions;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Filament\Resources\Requisitions\Schemas\RequisitionInfolistSchema;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaModalSchema;
use App\Filament\Support\OwwaTableDefaults;
use App\Models\Requisition;
use App\Models\User;
use App\Support\OwwaReferenceLabels;
use App\Support\RequisitionStatus;
use App\Support\RequisitionViewPresenter;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class RequisitionsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
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
                    modelLabel: RequisitionResource::getModelLabel(),
                ),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->recordUrl(null)
            ->recordAction('view');

        return OwwaTableDefaults::hideRedundantToolbarIcons($table);
    }
}
