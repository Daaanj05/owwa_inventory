<?php

namespace App\Filament\Pages\Auth;

use App\Services\EmailVerificationCodeService;
use App\Support\FriendlyMessages;
use App\Support\PasswordRules;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * @property-read Schema $form
 */
class VerifyEmailWithCode extends SimplePage
{
    use WithRateLimiting;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.auth.verify-email-with-code';

    public int $step = 1;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());
        }

        $this->form->fill();
    }

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return 'verify-email';
    }

    public static function getRelativeRouteName(\Filament\Panel $panel): string
    {
        return 'auth.verify-email-with-code';
    }

    public static function isEmailVerificationRequired(\Filament\Panel $panel): bool
    {
        return false;
    }

    public static function isMultiFactorAuthenticationRequired(\Filament\Panel $panel): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(match ($this->step) {
                1 => [
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->autocomplete()
                        ->autofocus(),
                ],
                2 => [
                    TextInput::make('code')
                        ->label('Verification code')
                        ->required()
                        ->length(6)
                        ->autocomplete('one-time-code')
                        ->autofocus(),
                ],
                default => [
                    TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable(filament()->arePasswordsRevealable())
                        ->required()
                        ->rule(Password::default())
                        ->same('passwordConfirmation')
                        ->helperText(PasswordRules::helperText())
                        ->autocomplete('new-password')
                        ->autofocus(),
                    TextInput::make('passwordConfirmation')
                        ->label('Confirm password')
                        ->password()
                        ->revealable(filament()->arePasswordsRevealable())
                        ->required()
                        ->autocomplete('new-password')
                        ->dehydrated(false),
                ],
            })
            ->statePath('data');
    }

    public function advance(): void
    {
        match ($this->step) {
            1 => $this->submitEmail(),
            2 => $this->submitCode(),
            default => $this->submitPassword(),
        };
    }

    public function submitEmail(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            Notification::make()
                ->title('Too many attempts')
                ->body('Please wait a moment and try again.')
                ->danger()
                ->send();

            return;
        }

        $data = $this->form->getState();
        $email = (string) ($data['email'] ?? $this->data['email'] ?? '');

        if ($email === '') {
            throw ValidationException::withMessages([
                'data.email' => 'The email field is required.',
            ]);
        }

        app(EmailVerificationCodeService::class)->requestSetupCodeForEmail($email);

        $this->data = [
            'email' => $email,
            'code' => '',
            'password' => '',
            'passwordConfirmation' => '',
        ];
        $this->goToStep(2);

        Notification::make()
            ->title('Check your email')
            ->body(FriendlyMessages::setupCodeSent())
            ->success()
            ->send();
    }

    public function submitCode(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            Notification::make()
                ->title('Too many attempts')
                ->body('Please wait a moment and try again.')
                ->danger()
                ->send();

            return;
        }

        $data = $this->form->getState();
        $email = (string) ($this->data['email'] ?? '');
        $code = (string) ($data['code'] ?? '');

        if (! app(EmailVerificationCodeService::class)->codeIsValid($email, $code)) {
            throw ValidationException::withMessages([
                'data.code' => FriendlyMessages::emailVerificationInvalidCode(),
            ]);
        }

        $this->data = array_merge($this->data ?? [], [
            'email' => $email,
            'code' => $code,
            'password' => '',
            'passwordConfirmation' => '',
        ]);
        $this->goToStep(3);
    }

    public function submitPassword(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            Notification::make()
                ->title('Too many attempts')
                ->body('Please wait a moment and try again.')
                ->danger()
                ->send();

            return;
        }

        $data = $this->form->getState();
        $email = (string) ($this->data['email'] ?? '');
        $code = (string) ($this->data['code'] ?? '');
        $password = (string) ($data['password'] ?? '');

        $user = app(EmailVerificationCodeService::class)->completeSetup($email, $code, $password);

        if ($user === null) {
            $this->data = array_merge($this->data ?? [], [
                'code' => '',
                'password' => '',
                'passwordConfirmation' => '',
            ]);
            $this->goToStep(2);

            throw ValidationException::withMessages([
                'data.code' => FriendlyMessages::emailVerificationInvalidCode(),
            ]);
        }

        Filament::auth()->login($user);

        if (request()->hasSession()) {
            request()->session()->regenerate();
            request()->session()->put([
                'password_hash_'.Filament::getAuthGuard() => $user->getAuthPassword(),
            ]);
        }

        Notification::make()
            ->title('Account ready')
            ->body(FriendlyMessages::accountSetupSuccess())
            ->success()
            ->send();

        $mfaUrl = Filament::getSetUpRequiredMultiFactorAuthenticationUrl();

        $this->redirect($mfaUrl ?: Filament::getUrl());
    }

    public function resendCode(): void
    {
        try {
            $this->rateLimit(3);
        } catch (TooManyRequestsException $exception) {
            Notification::make()
                ->title('Too many attempts')
                ->body('Please wait a moment and try again.')
                ->danger()
                ->send();

            return;
        }

        $email = (string) ($this->data['email'] ?? '');

        if ($email === '') {
            $this->goToStep(1);

            return;
        }

        app(EmailVerificationCodeService::class)->requestSetupCodeForEmail($email);

        Notification::make()
            ->title('Code sent')
            ->body(FriendlyMessages::setupCodeSent())
            ->success()
            ->send();
    }

    public function goBack(): void
    {
        if ($this->step <= 1) {
            return;
        }

        $nextStep = $this->step - 1;

        if ($nextStep === 1) {
            $this->data = [
                'email' => (string) ($this->data['email'] ?? ''),
                'code' => '',
                'password' => '',
                'passwordConfirmation' => '',
            ];
        }

        if ($nextStep === 2) {
            $this->data = array_merge($this->data ?? [], [
                'code' => '',
                'password' => '',
                'passwordConfirmation' => '',
            ]);
        }

        $this->goToStep($nextStep);
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
        $submitLabel = match ($this->step) {
            1 => 'Continue',
            2 => 'Verify code',
            default => 'Save password',
        };

        $footerActions = [
            Action::make('submit')
                ->label($submitLabel)
                ->submit('advance'),
        ];

        if ($this->step === 2) {
            $footerActions[] = Action::make('resendCode')
                ->label('Resend code')
                ->color('gray')
                ->action('resendCode');
        }

        if ($this->step > 1) {
            $footerActions[] = Action::make('goBack')
                ->label('Back')
                ->color('gray')
                ->action('goBack');
        }

        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('advance')
                    ->footer([
                        Actions::make($footerActions)->fullWidth(),
                    ]),
            ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Set up your account';
    }

    public function getHeading(): string|Htmlable|null
    {
        return match ($this->step) {
            1 => 'Enter your email',
            2 => 'Enter your verification code',
            default => 'Choose your password',
        };
    }

    public function getSubheading(): string|Htmlable|null
    {
        return match ($this->step) {
            1 => 'Use the email address your administrator registered for you. We will send a verification code to that inbox.',
            2 => 'Enter the 6-digit code we just emailed you.',
            default => 'Create a password you will use to sign in.',
        };
    }

    public function hasLogo(): bool
    {
        return false;
    }
}
