<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Support\ConfiguresOwwaViewAction;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\OwwaModalSchema;
use App\Models\User;
use App\Support\FriendlyMessages;
use App\Support\MailDelivery;
use App\Support\OwwaTransactionViewPresenter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Auth\Notifications\VerifyEmail as FilamentVerifyEmail;
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
