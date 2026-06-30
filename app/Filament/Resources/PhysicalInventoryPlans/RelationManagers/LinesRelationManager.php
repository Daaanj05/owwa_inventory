<?php

namespace App\Filament\Resources\PhysicalInventoryPlans\RelationManagers;

use App\Filament\Resources\PhysicalCountSessions\PhysicalCountSessionResource;
use App\Models\PhysicalInventoryPlanLine;
use App\Services\InventoryPlanLineStatusService;
use App\Services\InventoryPlanStartCountService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Schedule';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return str_contains($pageClass, 'ViewPhysicalInventoryPlan');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('planned_date')
                    ->label('Date')
                    ->date('M j, Y')
                    ->sortable(),
                TextColumn::make('office.name')
                    ->label('Office'),
                TextColumn::make('itemCategory.name')
                    ->label('Category'),
                TextColumn::make('computed_status')
                    ->label('Status')
                    ->state(fn (PhysicalInventoryPlanLine $record): string => app(InventoryPlanLineStatusService::class)->statusForLine($record))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        PhysicalInventoryPlanLine::STATUS_PENDING => 'Pending',
                        PhysicalInventoryPlanLine::STATUS_IN_PROGRESS => 'In progress',
                        PhysicalInventoryPlanLine::STATUS_COMPLETE => 'Complete',
                        PhysicalInventoryPlanLine::STATUS_OVERDUE => 'Overdue',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        PhysicalInventoryPlanLine::STATUS_COMPLETE => 'success',
                        PhysicalInventoryPlanLine::STATUS_IN_PROGRESS => 'warning',
                        PhysicalInventoryPlanLine::STATUS_OVERDUE => 'danger',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('planned_date')
            ->recordActions([
                Action::make('startCount')
                    ->label('Start count')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->visible(fn (PhysicalInventoryPlanLine $record): bool => $record->physical_count_session_id === null)
                    ->action(function (PhysicalInventoryPlanLine $record): void {
                        $user = Filament::auth()->user();
                        if ($user === null) {
                            return;
                        }

                        $session = app(InventoryPlanStartCountService::class)->startCount($record, $user);

                        $this->redirect(PhysicalCountSessionResource::getUrl('view', ['record' => $session]));
                    }),
                Action::make('continueCount')
                    ->label('Continue')
                    ->icon('heroicon-o-arrow-right')
                    ->visible(fn (PhysicalInventoryPlanLine $record): bool => $record->physicalCountSession !== null
                        && ! $record->physicalCountSession->isComplete())
                    ->url(fn (PhysicalInventoryPlanLine $record): string => PhysicalCountSessionResource::getUrl('view', [
                        'record' => $record->physical_count_session_id,
                    ])),
                Action::make('viewCount')
                    ->label('View count')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (PhysicalInventoryPlanLine $record): bool => $record->physicalCountSession?->isComplete() ?? false)
                    ->url(fn (PhysicalInventoryPlanLine $record): string => PhysicalCountSessionResource::getUrl('view', [
                        'record' => $record->physical_count_session_id,
                    ])),
            ])
            ->headerActions([])
            ->emptyStateHeading('No schedule lines')
            ->emptyStateDescription('Edit the schedule to add offices and planned dates.');
    }
}
