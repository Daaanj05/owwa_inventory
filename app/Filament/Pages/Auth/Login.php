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
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    protected string $view = 'filament.pages.auth.login';

    public function authenticate(): ?LoginResponse
    {
        // Always send users back to the current panel's dashboard,
        // not to any previously stored "intended" URL (like /admin).
        Session::forget('url.intended');

        $this->form->fill(array_merge(
            $this->form->getRawState(),
            array_filter($this->data ?? [], fn ($value): bool => filled($value)),
        ));

        $this->throwIfEmailUnverified();

        return parent::authenticate();
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
