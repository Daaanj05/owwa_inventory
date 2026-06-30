<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Support\PasswordRules;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class EditProfile extends BaseEditProfile
{
    protected Width|string|null $maxWidth = Width::TwoExtraLarge;

    /**
     * @var array<string, string>
     */
    protected array $extraBodyAttributes = [
        'class' => 'owwa-profile-page',
    ];

    public function getHeading(): string|Htmlable
    {
        return 'Account settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Update your name, email, or password.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profile information')
                    ->description('Your display name and login email.')
                    ->columns(2)
                    ->schema([
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                    ]),
                Section::make('Change password')
                    ->description('Leave blank to keep your current password. Your current password is required when changing your password or email.')
                    ->columns(1)
                    ->extraAttributes(['class' => 'owwa-profile-password-section'])
                    ->schema([
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                        $this->getCurrentPasswordFormComponent(),
                    ]),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(Filament::getUrl()),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
        ];
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('filament-panels::auth/pages/edit-profile.form.password.label'))
            ->validationAttribute(__('filament-panels::auth/pages/edit-profile.form.password.validation_attribute'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->rule(Password::default())
            ->showAllValidationMessages()
            ->helperText(PasswordRules::helperText())
            ->autocomplete('new-password')
            ->dehydrated(fn ($state): bool => filled($state))
            ->dehydrateStateUsing(fn ($state): string => Hash::make($state))
            ->live(debounce: 500)
            ->same('passwordConfirmation');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $passwordChanged = array_key_exists('password', $data);

        $record = parent::handleRecordUpdate($record, $data);

        if ($passwordChanged && $record instanceof User && $record->mustChangePassword()) {
            $record->forceFill(['must_change_password' => false])->save();
        }

        return $record;
    }
}
