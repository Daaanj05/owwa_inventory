<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaModalSchema;
use App\Models\User;
use App\Services\PasswordResetRequestService;
use App\Support\FriendlyMessages;
use App\Support\MailDelivery;
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
use Illuminate\Database\Eloquent\Builder;

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
                TextColumn::make('email_verified_at')
                    ->label('Verification')
                    ->badge()
                    ->state(fn (User $record): string => $record->hasVerifiedEmail() ? 'Verified' : 'Pending')
                    ->color(fn (User $record): string => $record->hasVerifiedEmail() ? 'success' : 'warning')
                    ->sortable(),
                TextColumn::make('pendingPasswordResetRequest.requested_at')
                    ->label('Password reset')
                    ->badge()
                    ->state(fn (User $record): ?string => $record->pendingPasswordResetRequest !== null ? 'Reset requested' : null)
                    ->color('warning')
                    ->placeholder('—'),
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
                SelectFilter::make('verification_status')
                    ->label('Verification')
                    ->options([
                        'verified' => 'Verified',
                        'pending' => 'Pending',
                    ])
                    ->placeholder('All statuses')
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'verified' => $query->whereNotNull('email_verified_at'),
                            'pending' => $query->whereNull('email_verified_at'),
                            default => $query,
                        };
                    }),
                SelectFilter::make('password_reset_status')
                    ->label('Password reset')
                    ->options([
                        'pending' => 'Reset requested',
                        'none' => 'No pending request',
                    ])
                    ->placeholder('All statuses')
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'pending' => $query->whereHas('pendingPasswordResetRequest'),
                            'none' => $query->whereDoesntHave('pendingPasswordResetRequest'),
                            default => $query,
                        };
                    }),
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
                                $notification = app(FilamentVerifyEmail::class);
                                $notification->url = User::guestEmailVerificationUrlFor($record);

                                $result = MailDelivery::notify($record, $notification);

                                if ($result->success && $result->wasQueued) {
                                    Notification::make()
                                        ->title('Verification email queued')
                                        ->body(FriendlyMessages::verificationResendQueued($record->email))
                                        ->success()
                                        ->send();

                                    return;
                                }

                                if ($result->success) {
                                    Notification::make()
                                        ->title('Verification email sent')
                                        ->body(FriendlyMessages::verificationResendSent($record->email))
                                        ->success()
                                        ->send();

                                    return;
                                }

                                Notification::make()
                                    ->title('Email could not be sent')
                                    ->body(FriendlyMessages::verificationResendFailed())
                                    ->warning()
                                    ->send();
                            }),
                        Action::make('sendPasswordResetEmail')
                            ->label('Send password reset email')
                            ->icon('heroicon-o-key')
                            ->visible(fn (User $record): bool => $record->canAccessPanel(Filament::getPanel('admin')))
                            ->action(function (User $record): void {
                                $admin = auth()->user();

                                if (! $admin instanceof User) {
                                    return;
                                }

                                $service = app(PasswordResetRequestService::class);
                                $pending = $record->pendingPasswordResetRequest;
                                $result = $pending !== null
                                    ? $service->sendResetEmail($pending, $admin)
                                    : $service->sendResetEmailForUser($record, $admin);

                                self::notifyPasswordResetEmailResult($record->email, $result);
                            }),
                        Action::make('dismissPasswordResetRequest')
                            ->label('Dismiss reset request')
                            ->icon('heroicon-o-x-circle')
                            ->color('gray')
                            ->visible(fn (User $record): bool => $record->pendingPasswordResetRequest !== null)
                            ->requiresConfirmation()
                            ->action(function (User $record): void {
                                $admin = auth()->user();
                                $pending = $record->pendingPasswordResetRequest;

                                if (! $admin instanceof User || $pending === null) {
                                    return;
                                }

                                app(PasswordResetRequestService::class)->dismiss($pending, $admin);

                                Notification::make()
                                    ->title('Reset request dismissed')
                                    ->body(FriendlyMessages::passwordResetRequestDismissed($record->email))
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

    protected static function notifyPasswordResetEmailResult(string $email, \App\Support\MailDeliveryResult $result): void
    {
        if ($result->success && $result->wasQueued) {
            Notification::make()
                ->title('Password reset email queued')
                ->body(FriendlyMessages::passwordResetEmailQueued($email))
                ->success()
                ->send();

            return;
        }

        if ($result->success) {
            Notification::make()
                ->title('Password reset email sent')
                ->body(FriendlyMessages::passwordResetEmailSent($email))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Email could not be sent')
            ->body(FriendlyMessages::passwordResetEmailFailed())
            ->warning()
            ->send();
    }
}
