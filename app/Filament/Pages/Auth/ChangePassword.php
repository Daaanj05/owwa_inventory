<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Concerns\ChallengesMultiFactorForSensitiveActions;
use App\Models\User;
use App\Support\PasswordRules;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasTopbar;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rules\Password;

class ChangePassword extends Page
{
    use ChallengesMultiFactorForSensitiveActions;
    use HasTopbar;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'change-password';

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected string $view = 'filament.pages.auth.change-password';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->mustChangePassword()) {
            $this->redirect(Filament::getUrl());

            return;
        }

        if ($this->userHasEnabledMultiFactorProvider($user)) {
            $this->prepareMultiFactorChallenge($user);
        }

        $this->form->fill();
    }

    public function changePassword(): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return;
        }

        if ($this->userHasEnabledMultiFactorProvider($user)) {
            $this->hitMultiFactorChallengeRateLimiter($user);
        }

        $data = $this->form->getState();

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
            ->body('Your password has been changed. You can now use the system.')
            ->success()
            ->send();

        $mfaUrl = Filament::getSetUpRequiredMultiFactorAuthenticationUrl();

        if (filled($mfaUrl) && ! $this->userHasEnabledMultiFactorProvider($user->fresh())) {
            $this->redirect($mfaUrl, navigate: true);

            return;
        }

        $this->redirect(Filament::getUrl(), navigate: true);
    }

    public function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        return $schema
            ->components([
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
                ...($user instanceof User ? $this->multiFactorChallengeFormComponents($user) : []),
            ])
            ->statePath('data');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Change your password';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Choose a new password';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && $this->userHasEnabledMultiFactorProvider($user)) {
            return 'Confirm with your authenticator or email code, then set a new password.';
        }

        return 'Choose a new password to continue.';
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('changePassword')
                ->label('Save new password')
                ->submit('changePassword'),
        ];
    }

    protected function hasFullWidthFormActions(): bool
    {
        return true;
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('changePassword')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->fullWidth($this->hasFullWidthFormActions())
                    ->key('form-actions'),
            ]);
    }

    public function hasTopbar(): bool
    {
        return false;
    }

    public function hasLogo(): bool
    {
        return false;
    }

    public static function isMultiFactorAuthenticationRequired(\Filament\Panel $panel): bool
    {
        return false;
    }
}
