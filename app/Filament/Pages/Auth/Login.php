<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Support\FriendlyMessages;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    protected string $view = 'filament.pages.auth.login';

    private const int MAX_LOGIN_ATTEMPTS = 5;

    private const int LOGIN_DECAY_SECONDS = 60;

    public function authenticate(): ?LoginResponse
    {
        // Always send users back to the current panel's dashboard,
        // not to any previously stored "intended" URL (like /admin).
        Session::forget('url.intended');

        $this->form->fill(array_merge(
            $this->form->getRawState(),
            array_filter($this->data ?? [], fn ($value): bool => filled($value)),
        ));

        $this->ensureIsNotRateLimited();

        $this->throwIfEmailUnverified();

        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            RateLimiter::hit($this->throttleKey(), self::LOGIN_DECAY_SECONDS);

            throw $exception;
        }

        RateLimiter::clear($this->throttleKey());

        return $response;
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_LOGIN_ATTEMPTS)) {
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
                'data.email' => FriendlyMessages::emailNotVerifiedLogin(),
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
