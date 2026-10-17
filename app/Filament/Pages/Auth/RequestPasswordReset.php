<?php

namespace App\Filament\Pages\Auth;

use App\Services\PasswordResetCodeService;
use App\Support\FriendlyMessages;
use App\Support\PasswordRules;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class RequestPasswordReset extends BaseRequestPasswordReset
{
    protected string $view = 'filament.pages.auth.request-password-reset';

    public int $step = 1;

    public function advance(): void
    {
        if ($this->step === 1) {
            $this->request();

            return;
        }

        $this->resetPassword();
    }

    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();
        $email = (string) ($data['email'] ?? $this->data['email'] ?? '');

        app(PasswordResetCodeService::class)->issueAndSendForEmail($email);

        $this->data = [
            'email' => $email,
            'code' => '',
            'password' => '',
            'passwordConfirmation' => '',
        ];
        $this->goToStep(2);

        Notification::make()
            ->title('Check your email')
            ->body(FriendlyMessages::passwordResetCodeSent())
            ->success()
            ->send();
    }

    public function resetPassword(): ?PasswordResetResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        $email = (string) ($this->data['email'] ?? $data['email'] ?? '');

        $ok = app(PasswordResetCodeService::class)->resetWithCode(
            $email,
            (string) ($data['code'] ?? ''),
            (string) ($data['password'] ?? ''),
        );

        if (! $ok) {
            throw ValidationException::withMessages([
                'data.code' => FriendlyMessages::passwordResetInvalidCode(),
            ]);
        }

        Notification::make()
            ->title('Password updated')
            ->body('Your password has been changed. You can sign in now.')
            ->success()
            ->send();

        return app(PasswordResetResponse::class);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->step === 1
                ? [
                    TextInput::make('email')
                        ->label(__('filament-panels::auth/pages/password-reset/request-password-reset.form.email.label'))
                        ->placeholder("\u{200B}")
                        ->email()
                        ->required()
                        ->autocomplete()
                        ->autofocus(),
                ]
                : [
                    TextInput::make('code')
                        ->label('Reset code')
                        ->required()
                        ->length(6)
                        ->autocomplete('one-time-code')
                        ->autofocus(),
                    TextInput::make('password')
                        ->label('New password')
                        ->password()
                        ->revealable(filament()->arePasswordsRevealable())
                        ->required()
                        ->rule(Password::default())
                        ->same('passwordConfirmation')
                        ->helperText(PasswordRules::helperText())
                        ->autocomplete('new-password'),
                    TextInput::make('passwordConfirmation')
                        ->label('Confirm new password')
                        ->password()
                        ->revealable(filament()->arePasswordsRevealable())
                        ->required()
                        ->autocomplete('new-password')
                        ->dehydrated(false),
                ])
            ->statePath('data');
    }

    protected function goToStep(int $step): void
    {
        $this->step = $step;
        $this->cacheSchema('form', null);
        $this->cacheSchema('content', null);
        $this->form->fill($this->data ?? []);
    }

    public function content(Schema $schema): Schema
    {
        $submitLabel = $this->step === 1
            ? __('filament-panels::auth/pages/password-reset/request-password-reset.form.actions.request.label')
            : 'Save new password';

        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('advance')
                    ->footer([
                        Actions::make([
                            Action::make('submit')
                                ->label($submitLabel)
                                ->submit('advance'),
                        ])->fullWidth(),
                    ]),
            ]);
    }

    public function getHeading(): string|Htmlable|null
    {
        return $this->step === 1
            ? __('filament-panels::auth/pages/password-reset/request-password-reset.heading')
            : 'Enter your reset code';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->step === 1
            ? FriendlyMessages::passwordResetCodeSent()
            : 'Use the 6-digit code from your email, then choose a new password.';
    }

    protected function getSentNotification(string $status): ?Notification
    {
        return null;
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/password-reset/request-password-reset.form.email.label'))
            ->placeholder("\u{200B}")
            ->email()
            ->required()
            ->autocomplete()
            ->autofocus();
    }
}
