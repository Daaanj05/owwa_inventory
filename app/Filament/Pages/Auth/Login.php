<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\TrustedDeviceService;
use App\Support\FriendlyMessages;
use App\Support\LoginRememberedEmail;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\MultiFactor\Contracts\HasBeforeChallengeHook;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Models\Contracts\FilamentUser;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    protected string $view = 'filament.pages.auth.login';

    public function mount(): void
    {
        $this->redirectAwayFromCredentialQueryString();

        parent::mount();

        $rememberedEmail = LoginRememberedEmail::read();
        if ($rememberedEmail === null) {
            return;
        }

        $this->form->fill([
            'email' => $rememberedEmail,
            'remember' => true,
        ]);

        $this->data = array_merge($this->data ?? [], [
            'email' => $rememberedEmail,
            'remember' => true,
        ]);
    }

    protected function redirectAwayFromCredentialQueryString(): void
    {
        $query = request()->query();

        $hasCredentialQuery = array_key_exists('data.email', $query)
            || array_key_exists('data.password', $query)
            || array_key_exists('data_email', $query)
            || array_key_exists('data_password', $query)
            || (is_array($query['data'] ?? null) && (
                array_key_exists('email', $query['data'])
                || array_key_exists('password', $query['data'])
            ));

        if (! $hasCredentialQuery) {
            return;
        }

        $loginUrl = Filament::getCurrentOrDefaultPanel()?->getLoginUrl() ?? url('/login');
        $safeQuery = array_filter([
            'reauth' => $query['reauth'] ?? null,
            'logged_out' => $query['logged_out'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $target = $safeQuery === []
            ? $loginUrl
            : $loginUrl.'?'.http_build_query($safeQuery);

        throw new HttpResponseException(new RedirectResponse($target));
    }

    public function authenticate(): ?LoginResponse
    {
        Session::forget('url.intended');

        $rememberEmailOnly = (bool) ($this->data['remember'] ?? false);

        $this->form->fill(array_merge(
            $this->form->getRawState(),
            array_filter(
                $this->data ?? [],
                fn (mixed $value, string|int $key): bool => $key === 'remember' || filled($value),
                ARRAY_FILTER_USE_BOTH,
            ),
            ['remember' => false],
        ));

        $this->ensureIsNotRateLimited();

        $this->throwIfEmailUnverified();

        try {
            $response = $this->authenticateWithTrustedDeviceSupport();
        } catch (ValidationException $exception) {
            RateLimiter::hit($this->throttleKey(), $this->loginDecaySeconds());

            throw $exception;
        }

        RateLimiter::clear($this->throttleKey());

        if ($response !== null) {
            $this->syncRememberedEmailCookie($rememberEmailOnly);
        }

        return $response;
    }

    protected function authenticateWithTrustedDeviceSupport(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        /** @var SessionGuard $authGuard */
        $authGuard = Filament::auth();

        $authProvider = $authGuard->getProvider(); /** @phpstan-ignore-line */
        $credentials = $this->getCredentialsFromFormData($data);

        $user = $authProvider->retrieveByCredentials($credentials);

        if ((! $user) || (! $authProvider->validateCredentials($user, $credentials))) {
            $this->userUndertakingMultiFactorAuthentication = null;

            $this->fireFailedEvent($authGuard, $user, $credentials);
            $this->throwFailureValidationException();
        }

        $trustedDevices = app(TrustedDeviceService::class);
        $completedMultiFactorChallenge = false;

        if (
            filled($this->userUndertakingMultiFactorAuthentication) &&
            (decrypt($this->userUndertakingMultiFactorAuthentication) === $user->getAuthIdentifier())
        ) {
            if ($this->isMultiFactorChallengeRateLimited($user)) {
                return null;
            }

            $this->multiFactorChallengeForm->validate();
            $completedMultiFactorChallenge = true;
        } elseif (
            $this->userHasEnabledMultiFactorProvider($user)
            && (! $user instanceof User || ! $trustedDevices->isCurrentDeviceTrusted($user))
        ) {
            foreach (Filament::getMultiFactorAuthenticationProviders() as $multiFactorAuthenticationProvider) {
                if (! $multiFactorAuthenticationProvider->isEnabled($user)) {
                    continue;
                }

                $this->userUndertakingMultiFactorAuthentication = encrypt($user->getAuthIdentifier());

                if ($multiFactorAuthenticationProvider instanceof HasBeforeChallengeHook) {
                    $multiFactorAuthenticationProvider->beforeChallenge($user);
                }

                break;
            }

            if (filled($this->userUndertakingMultiFactorAuthentication)) {
                $this->multiFactorChallengeForm->fill();

                return null;
            }
        }

        if (! $authGuard->attemptWhen($credentials, function (Authenticatable $user): bool {
            if (! ($user instanceof FilamentUser)) {
                return true;
            }

            return $user->canAccessPanel(Filament::getCurrentOrDefaultPanel());
        }, false)) {
            $this->fireFailedEvent($authGuard, $user, $credentials);
            $this->throwFailureValidationException();
        }

        if (
            $completedMultiFactorChallenge
            && (bool) ($this->data['multiFactor']['rememberDevice'] ?? false)
            && $user instanceof User
        ) {
            $trustedDevices->trustCurrentDevice($user);
        }

        session()->regenerate();

        return app(LoginResponse::class);
    }

    public function defaultMultiFactorChallengeForm(Schema $schema): Schema
    {
        return $schema
            ->components(function (): array {
                if (blank($this->userUndertakingMultiFactorAuthentication)) {
                    return [];
                }

                $authProvider = Filament::auth()->getProvider(); /** @phpstan-ignore-line */
                $user = $authProvider->retrieveById(decrypt($this->userUndertakingMultiFactorAuthentication));

                $enabledMultiFactorAuthenticationProviders = array_filter(
                    Filament::getMultiFactorAuthenticationProviders(),
                    fn (MultiFactorAuthenticationProvider $multiFactorAuthenticationProvider): bool => $multiFactorAuthenticationProvider->isEnabled($user)
                );

                $days = app(TrustedDeviceService::class)->days();

                return [
                    ...Arr::wrap($this->getMultiFactorProviderFormComponent()),
                    ...collect($enabledMultiFactorAuthenticationProviders)
                        ->map(fn (MultiFactorAuthenticationProvider $multiFactorAuthenticationProvider): Component => Group::make($multiFactorAuthenticationProvider->getChallengeFormComponents($user))
                            ->statePath($multiFactorAuthenticationProvider->getId())
                            ->when(
                                count($enabledMultiFactorAuthenticationProviders) > 1,
                                fn (Group $group) => $group->visible(fn (Get $get): bool => $get('provider') === $multiFactorAuthenticationProvider->getId())
                            ))
                        ->all(),
                    Checkbox::make('rememberDevice')
                        ->label("Remember this device for {$days} days")
                        ->helperText('Skip the verification code on this browser until the period ends. You will still need your password.'),
                ];
            })
            ->statePath('data.multiFactor');
    }

    protected function userHasEnabledMultiFactorProvider(Authenticatable $user): bool
    {
        foreach (Filament::getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider->isEnabled($user)) {
                return true;
            }
        }

        return false;
    }

    protected function syncRememberedEmailCookie(bool $rememberEmailOnly): void
    {
        $email = (string) ($this->data['email'] ?? '');

        if ($rememberEmailOnly) {
            LoginRememberedEmail::remember($email);

            return;
        }

        LoginRememberedEmail::forget();
    }

    protected function getRememberFormComponent(): Component
    {
        return Checkbox::make('remember')
            ->label('Remember email');
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), $this->loginMaxAttempts())) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'data.email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    protected function loginMaxAttempts(): int
    {
        return max(1, (int) config('inventory.login_max_attempts', 5));
    }

    protected function loginDecaySeconds(): int
    {
        return max(1, (int) config('inventory.login_decay_seconds', 60));
    }

    protected function throttleKey(): string
    {
        $email = Str::lower((string) ($this->data['email'] ?? ''));

        return 'filament-login:'.sha1($email.'|'.request()->ip());
    }

    protected function throwIfEmailUnverified(): void
    {
        $data = $this->form->getState();

        $authGuard = Filament::auth();
        $authProvider = $authGuard->getProvider();
        $credentials = $this->getCredentialsFromFormData($data);

        $user = $authProvider->retrieveByCredentials($credentials);

        if (
            $user instanceof MustVerifyEmail
            && $user instanceof User
            && $authProvider->validateCredentials($user, $credentials)
            && ! $user->hasVerifiedEmail()
            && $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())
        ) {
            throw ValidationException::withMessages([
                'data.email' => FriendlyMessages::emailNotVerifiedLogin(User::panelSetupUrlFor($user)),
            ]);
        }
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/login.form.email.label'))
            ->placeholder("\u{200B}")
            ->email()
            ->required()
            ->autocomplete()
            ->autofocus();
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('filament-panels::auth/pages/login.form.password.label'))
            ->placeholder("\u{200B}")
            ->hint(filament()->hasPasswordReset() ? new \Illuminate\Support\HtmlString(\Illuminate\Support\Facades\Blade::render('<x-filament::link :href="filament()->getRequestPasswordResetUrl()"> {{ __(\'filament-panels::auth/pages/login.actions.request_password_reset.label\') }}</x-filament::link>')) : null)
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('current-password')
            ->required();
    }
}
