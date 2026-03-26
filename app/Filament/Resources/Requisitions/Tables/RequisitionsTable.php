<?php

namespace App\Filament\Resources\Requisitions\Tables;

use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Services\FiscalYearService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class RequisitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_code')
                    ->label('Reference')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst($state ?? 'pending'))
                    ->color(fn (?string $state): string => match ($state) {
                        'pending'   => 'warning',
                        'approved'  => 'success',
                        'rejected'  => 'danger',
                        'fulfilled' => 'info',
                        default     => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Date filed')
                    ->date('M d, Y')
                    ->sortable(),
                TextColumn::make('requestedBy.name')
                    ->label('Requested by')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('department.name')
                    ->label('Department')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('office.name')
                    ->label('Office')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    ->options([
                        'pending'   => 'Pending',
                        'approved'  => 'Approved',
                        'rejected'  => 'Rejected',
                        'fulfilled' => 'Fulfilled',
                    ])
                    ->placeholder('All statuses'),
                SelectFilter::make('department_id')
                    ->label('Department')
                    ->relationship(
                        'department',
                        'name',
                        fn ($query) => $query->forFiscalYear(app(FiscalYearService::class)->current()?->id)->active()
                    )
                    ->searchable()
                    ->preload()
                    ->placeholder('All departments'),
            ])
            ->emptyStateHeading('No requisitions yet')
            ->emptyStateDescription('Requisitions submitted by employees will appear here.')
            ->emptyStateIcon('heroicon-o-document-text')
            ->recordActions([
                EditAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve requisition')
                    ->modalDescription('This will mark the requisition as approved.')
                    ->visible(fn (Requisition $record): bool => auth()->user()?->isSupplyCustodian() && $record->status === Requisition::STATUS_PENDING)
                    ->action(function (Requisition $record): void {
                        $record->update([
                            'status'      => Requisition::STATUS_APPROVED,
                            'approved_by' => auth()->id(),
                            'approved_at' => now(),
                        ]);
                        Notification::make()->title('Requisition approved')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Reject requisition')
                    ->modalDescription('This will mark the requisition as rejected.')
                    ->visible(fn (Requisition $record): bool => auth()->user()?->isSupplyCustodian() && $record->status === Requisition::STATUS_PENDING)
                    ->action(function (Requisition $record): void {
                        $record->update([
                            'status'      => Requisition::STATUS_REJECTED,
                            'approved_by' => auth()->id(),
                            'approved_at' => now(),
                        ]);
                        Notification::make()->title('Requisition rejected')->danger()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('compile')
                        ->label('Compile into one requisition')
                        ->icon('heroicon-o-document-duplicate')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading('Compile requisitions')
                        ->modalDescription('Create one consolidated requisition from the selected employee requisitions and send it to the Supply Custodian. Quantities for the same item will be combined.')
                        ->visible(fn (): bool => (bool) auth()->user()?->isAuthorizedPersonnel())
                        ->action(function (Collection $records): void {
                            $user = auth()->user();
                            if (! $user || ! $user->office_id) {
                                Notification::make()
                                    ->title('You must be assigned to an office to compile.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $pending = $records->filter(
                                fn (Requisition $r): bool => $r->status === Requisition::STATUS_PENDING
                            );
                            if ($pending->isEmpty()) {
                                Notification::make()
                                    ->title('Select at least one pending requisition to compile.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $requisition = Requisition::create([
                                'office_id' => $user->office_id,
                                'department_id' => $user->department_id,
                                'requested_by' => $user->id,
                                'status' => Requisition::STATUS_PENDING,
                            ]);

                            $itemQuantities = [];
                            foreach ($pending as $req) {
                                $req->load('items');
                                foreach ($req->items as $item) {
                                    $id = $item->item_id;
                                    $itemQuantities[$id] = ($itemQuantities[$id] ?? 0) + $item->quantity;
                                }
                            }
                            foreach ($itemQuantities as $itemId => $qty) {
                                RequisitionItem::create([
                                    'requisition_id' => $requisition->id,
                                    'item_id' => $itemId,
                                    'quantity' => $qty,
                                ]);
                            }

                            Notification::make()
                                ->title('Requisition compiled')
                                ->body("Created {$requisition->reference_code} with " . count($itemQuantities) . " item type(s). The Supply Custodian will see it in their list.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
