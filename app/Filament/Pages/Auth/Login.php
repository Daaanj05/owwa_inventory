<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Support\FriendlyMessages;
use App\Support\LoginRememberedEmail;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
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

        // Browsers/PHP may expose "data.email" as "data_email" after a native GET submit.
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
        // Always send users back to the current panel's dashboard,
        // not to any previously stored "intended" URL.
        Session::forget('url.intended');

        // Checkbox means "remember email" only — never Laravel stay-logged-in.
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
            $response = parent::authenticate();
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
