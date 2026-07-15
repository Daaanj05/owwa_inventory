<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Concerns\InteractsWithAccountNavigation;
use App\Filament\Concerns\InteractsWithProfileUser;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditProfile extends BaseEditProfile
{
    use InteractsWithAccountNavigation;
    use InteractsWithProfileUser;

    protected Width|string|null $maxWidth = Width::TwoExtraLarge;

    public function mount(): void
    {
        $this->loadProfileUserRelations();

        parent::mount();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->backfillNamePartsFromCombinedName($data);
    }

    protected function getAccountActiveTab(): string
    {
        return 'profile';
    }

    public function getTitle(): string|Htmlable
    {
        return '';
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['owwa-account-page'];
    }

    public function getHeading(): string|Htmlable
    {
        return 'Profile';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Update your name and email. Organization details are managed by your System Admin.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.pages.auth.partials.profile-hero'),
                $this->getFormContentComponent(),
            ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return parent::defaultForm($schema)
            ->inlineLabel(false);
    }

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profile information')
                    ->description('Your display name and login email.')
                    ->columns(2)
                    ->extraAttributes(['class' => 'owwa-account-section'])
                    ->schema([
                        TextInput::make('first_name')
                            ->label('First name')
                            ->required()
                            ->maxLength(255)
                            ->autofocus()
                            ->columnSpan(1),
                        TextInput::make('middle_name')
                            ->label('Middle name')
                            ->maxLength(255)
                            ->columnSpan(1),
                        TextInput::make('last_name')
                            ->label('Last name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(1),
                        $this->getEmailFormComponent()
                            ->columnSpan(1),
                        $this->getCurrentPasswordFormComponent()
                            ->columnSpanFull(),
                    ]),
                Section::make('Organization')
                    ->description('Contact your System Admin to change role or office assignment.')
                    ->columns(2)
                    ->extraAttributes(['class' => 'owwa-account-section owwa-account-org-section'])
                    ->schema([
                        Placeholder::make('role_display')
                            ->label('Role')
                            ->content(fn (): string => self::roleLabel($this->profileUser()))
                            ->extraAttributes(['class' => 'owwa-account-detail-field']),
                        Placeholder::make('office_display')
                            ->label('Office')
                            ->content(fn (): string => $this->profileUser()->office?->name ?? '—')
                            ->visible(fn (): bool => $this->showsSingleOfficeDepartment())
                            ->extraAttributes(['class' => 'owwa-account-detail-field']),
                        Placeholder::make('department_display')
                            ->label('Sub-Office/Department')
                            ->content(fn (): string => $this->profileUser()->department?->name ?? '—')
                            ->visible(fn (): bool => $this->showsSingleOfficeDepartment())
                            ->extraAttributes(['class' => 'owwa-account-detail-field']),
                        Placeholder::make('assignments_display')
                            ->label('Handled offices & sub-offices/departments')
                            ->content(fn (): Htmlable|string => $this->formatAssignmentsList())
                            ->visible(fn (): bool => $this->profileUser()->isUnitConsolidator())
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'owwa-account-detail-field']),
                    ]),
            ]);
    }

    /**
     * @return array<\Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->accountBackAction(),
        ];
    }

    /**
     * @return array<\Filament\Actions\Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->label('Save changes'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Arr::only($data, [
            'first_name',
            'middle_name',
            'last_name',
            'email',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return parent::handleRecordUpdate($record, $data);
    }
}
