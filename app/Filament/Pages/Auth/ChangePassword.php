<?php

namespace App\Filament\Pages\Auth;

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
    use HasTopbar;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'change-password';

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected string $view = 'filament-panels::pages.simple';

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

        $this->form->fill();
    }

    public function changePassword(): void
    {
        $data = $this->form->getState();

        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return;
        }

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

        $this->redirect(Filament::getUrl(), navigate: true);
    }

    public function form(Schema $schema): Schema
    {
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
        return 'You must set a new password before continuing.';
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

    public function hasLogo(): bool
    {
        return true;
    }
}
