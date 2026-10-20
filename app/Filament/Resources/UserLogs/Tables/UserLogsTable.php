<?php

namespace App\Filament\Resources\UserLogs\Tables;

use App\Filament\Resources\UserLogs\Schemas\UserLogInfolist;
use App\Filament\Resources\UserLogs\UserLogResource;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaModalSchema;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserLog;
use App\Support\OwwaTransactionViewPresenter;
use App\Support\UserActivityLogDisplay;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class UserLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user']))
            ->columns([
                TextColumn::make('user.name')
                    ->label('User')
                    ->sortable()
                    ->searchable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                TextColumn::make('user.role')
                    ->label('Role')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state, UserLog $record): string => $record->user?->roleLabel() ?? '—')
                    ->color(fn (UserLog $record): string => match ($record->user?->role) {
                        User::ROLE_SYSTEM_ADMIN => 'danger',
                        User::ROLE_SUPPLY_CUSTODIAN => 'primary',
                        User::ROLE_UNIT_CONSOLIDATOR => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('logged_in_at')
                    ->label('Logged In At')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('logged_out_at')
                    ->label('Logged Out At')
                    ->formatStateUsing(fn (?string $state, UserLog $record): string => $record->isOpen()
                        ? 'Active'
                        : ($record->logged_out_at?->format('M j, Y g:i A') ?? '—'))
                    ->sortable(),
                TextColumn::make('session_actions_count')
                    ->label('Actions')
                    ->state(fn (UserLog $record): int => $record->sessionActivitiesCount())
                    ->sortable(false),
                TextColumn::make('archived_at')
                    ->label('Archived')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('logged_in_at', 'desc')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    OwwaModalSchema::withHero(
                        fn (UserLog $record): array => OwwaTransactionViewPresenter::forUserLog($record),
                        UserLogInfolist::modalDetailSections(),
                    ),
                    modelLabel: UserLogResource::getModelLabel(),
                )->registerModalActions([
                    self::activityDetailAction(),
                ]),
            ])
            ->recordUrl(null)
            ->recordAction('view');
    }

    protected static function activityDetailAction(): Action
    {
        return Action::make('activityDetail')
            ->modalHeading(function (array $arguments): string {
                $activity = self::activityFromArguments($arguments);

                return $activity instanceof UserActivityLog
                    ? UserActivityLogDisplay::summary($activity)
                    : 'Activity details';
            })
            ->modalDescription(function (array $arguments): ?string {
                $activity = self::activityFromArguments($arguments);

                if (! $activity instanceof UserActivityLog) {
                    return null;
                }

                $when = $activity->created_at?->format('M j, Y g:i A') ?? '—';
                $action = str_replace('_', ' ', (string) $activity->action);

                return "{$when} · {$action}";
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalWidth('2xl')
            ->overlayParentActions()
            ->modalContent(function (array $arguments): View {
                $activity = self::activityFromArguments($arguments);

                return view('filament.partials.user-log-activity-detail', [
                    'lines' => $activity instanceof UserActivityLog
                        ? UserActivityLogDisplay::detailsFor($activity)
                        : [],
                ]);
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected static function activityFromArguments(array $arguments): ?UserActivityLog
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! $actor->isSystemAdmin()) {
            abort(403);
        }

        $activityId = (int) ($arguments['activityId'] ?? 0);

        if ($activityId <= 0) {
            return null;
        }

        return UserActivityLog::query()->find($activityId);
    }
}
