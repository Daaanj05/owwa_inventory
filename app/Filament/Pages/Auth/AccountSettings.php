<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Concerns\ChallengesMultiFactorForSensitiveActions;
use App\Filament\Concerns\InteractsWithAccountNavigation;
use App\Filament\Concerns\InteractsWithProfileUser;
use App\Models\TrustedDevice;
use App\Services\EmailVerificationCodeService;
use App\Services\TrustedDeviceService;
use App\Support\PasswordRules;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountSettings extends Page
{
    use ChallengesMultiFactorForSensitiveActions;
    use InteractsWithAccountNavigation;
    use InteractsWithProfileUser;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'account/settings';

    protected static ?string $title = 'Settings';

    protected string $view = 'filament.pages.auth.account-settings';

    protected Width|string|null $maxWidth = Width::TwoExtraLarge;

    public function mount(): void
    {
        $this->loadProfileUserRelations();
        app(TrustedDeviceService::class)->pruneExpiredForUser($this->profileUser());
    }

    /**
     * @return Collection<int, TrustedDevice>
     */
    public function activeTrustedDevices(): Collection
    {
        return $this->profileUser()
            ->trustedDevices()
            ->where('expires_at', '>', now())
            ->orderByDesc('last_used_at')
            ->get();
    }

    public function revokeTrustedDevice(int $deviceId): void
    {
        $user = $this->profileUser();
        $device = TrustedDevice::query()
            ->where('user_id', $user->id)
            ->whereKey($deviceId)
            ->first();

        if ($device === null) {
            return;
        }

        app(TrustedDeviceService::class)->revoke($device, $user);

        Notification::make()
            ->title('Device removed')
            ->body('That browser will need a verification code the next time you sign in.')
            ->success()
            ->send();
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['owwa-account-page'];
    }

    public function getTitle(): string|Htmlable
    {
        return '';
    }

    protected function getAccountActiveTab(): string
    {
        return 'settings';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Manage sign-in security and verification.';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->accountBackAction(),
        ];
    }

    public function resendVerificationAction(): Action
    {
        return Action::make('resendVerification')
            ->label('Resend email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => ! $this->profileUser()->hasVerifiedEmail())
            ->action(function (): void {
                $user = $this->profileUser();

                if ($user->hasVerifiedEmail()) {
                    return;
                }

                $result = app(EmailVerificationCodeService::class)->sendSetupInvite($user);

                if (! $result->success) {
                    Notification::make()
                        ->title('Could not send setup email')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Setup email sent')
                    ->body('Check your inbox for the account setup link.')
                    ->success()
                    ->send();
            });
    }

    public function changePasswordAction(): Action
    {
        return Action::make('changePassword')
            ->label('Change password')
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->size('sm')
            ->modalHeading('Change password')
            ->modalDescription('Enter your current password, a verification code, then choose a new password.')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Update password')
            ->mountUsing(function (): void {
                $this->prepareMultiFactorChallenge($this->profileUser());
            })
            ->form(fn (): array => [
                TextInput::make('currentPassword')
                    ->label('Current password')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->autocomplete('current-password')
                    ->currentPassword(guard: Filament::getAuthGuard())
                    ->required(),
                TextInput::make('password')
                    ->label('New password')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->rule(Password::default())
                    ->showAllValidationMessages()
                    ->helperText(PasswordRules::helperText())
                    ->same('passwordConfirmation')
                    ->autocomplete('new-password')
                    ->required(),
                TextInput::make('passwordConfirmation')
                    ->label('Confirm new password')
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->autocomplete('new-password')
                    ->dehydrated(false)
                    ->required(),
                ...$this->multiFactorChallengeFormComponents($this->profileUser()),
            ])
            ->action(function (array $data): void {
                $user = $this->profileUser();

                if (! $this->userHasEnabledMultiFactorProvider($user)) {
                    throw ValidationException::withMessages([
                        'password' => 'Set up multi-factor authentication before changing your password.',
                    ]);
                }

                $this->hitMultiFactorChallengeRateLimiter($user);

                $user->forceFill([
                    'password' => $data['password'],
                    'must_change_password' => false,
                ])->save();

                if (request()->hasSession()) {
                    request()->session()->put([
                        'password_hash_'.Filament::getAuthGuard() => $user->getAuthPassword(),
                    ]);
                }

                Notification::make()
                    ->title('Password updated')
                    ->body('Your password has been changed successfully.')
                    ->success()
                    ->send();
            });
    }
}
