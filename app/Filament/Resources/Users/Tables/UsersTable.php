<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaModalSchema;
use App\Models\User;
use App\Support\OwwaTransactionViewPresenter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Auth\Notifications\VerifyEmail as FilamentVerifyEmail;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(function (string $state): string {
                        return match ($state) {
                            User::ROLE_SYSTEM_ADMIN => 'System Admin',
                            User::ROLE_SUPPLY_CUSTODIAN => 'Supply Custodian',
                            User::ROLE_UNIT_CONSOLIDATOR => 'Unit Consolidator',
                            User::ROLE_EMPLOYEE => 'Employee',
                            default => $state,
                        };
                    })
                    ->color(function (string $state): string {
                        return match ($state) {
                            User::ROLE_SYSTEM_ADMIN => 'danger',
                            User::ROLE_SUPPLY_CUSTODIAN => 'primary',
                            User::ROLE_UNIT_CONSOLIDATOR => 'info',
                            default => 'gray',
                        };
                    })
                    ->sortable(),
                TextColumn::make('office.name')
                    ->label('Office')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('department.name')
                    ->label('Department')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('role')
                    ->options([
                        User::ROLE_SYSTEM_ADMIN => 'System Admin',
                        User::ROLE_SUPPLY_CUSTODIAN => 'Supply Custodian',
                        User::ROLE_UNIT_CONSOLIDATOR => 'Unit Consolidator',
                        User::ROLE_EMPLOYEE => 'Employee',
                    ])
                    ->placeholder('All roles'),
                SelectFilter::make('office_id')
                    ->label('Office')
                    ->relationship('office', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('All offices'),
            ])
            ->emptyStateHeading('No users yet')
            ->emptyStateDescription('Add system users here. Supply Custodians can approve requisitions and manage inventory.')
            ->emptyStateIcon('heroicon-o-users')
            ->recordActions([
                ConfiguresOwwaViewAction::make(
                    OwwaModalSchema::withHero(
                        fn (User $record): array => OwwaTransactionViewPresenter::forUser($record),
                        UserInfolist::modalDetailSections(),
                    ),
                    [
                        OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_STANDARD),
                        Action::make('resendVerification')
                            ->label('Resend verification email')
                            ->icon('heroicon-o-envelope')
                            ->visible(fn (User $record): bool => ! $record->hasVerifiedEmail())
                            ->action(function (User $record): void {
                                $panel = Filament::getPanel($record->isSystemAdmin() ? 'system-admin' : 'admin');
                                $notification = app(FilamentVerifyEmail::class);
                                $notification->url = $panel->getVerifyEmailUrl($record);
                                $record->notify($notification);

                                Notification::make()
                                    ->title('Verification email sent')
                                    ->body("A new verification link was sent to {$record->email}.")
                                    ->success()
                                    ->send();
                            }),
                    ],
                ),
                ActionGroup::make([
                    OwwaFormModalDefaults::editAction(OwwaFormModalDefaults::WIDTH_STANDARD),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray'),
            ])
            ->recordUrl(null)
            ->recordAction('view')
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
