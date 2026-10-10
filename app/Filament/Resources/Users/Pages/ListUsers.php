<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\HasSetupActiveTabToolbar;
use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\UserAssignmentActionHooks;
use App\Models\User;
use App\Notifications\UserWelcomeNotification;
use App\Services\PasswordResetRequestService;
use App\Support\FriendlyMessages;
use App\Support\MailDelivery;
use App\Support\TemporaryPassword;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListUsers extends ListRecords
{
    use HasSetupActiveTabToolbar;
    use HasSystemAdminWizardHeading;

    protected static string $resource = UserResource::class;

    /**
     * Filament schemas sometimes call `getRecord()` even on "list" pages.
     * List pages don't have a selected record, so we return `null`.
     */
    public function getRecord(): mixed
    {
        return null;
    }

    public function mount(): void
    {
        parent::mount();

        app(PasswordResetRequestService::class)->pruneExpired();
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('role', '!=', User::ROLE_SYSTEM_ADMIN))
                ->excludeQueryWhenResolvingRecord(),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('role', User::ROLE_SYSTEM_ADMIN))
                ->excludeQueryWhenResolvingRecord(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    protected function getHeaderActions(): array
    {
        $generatedPassword = null;
        $welcomeMailResult = null;
        $pendingAssignments = [];

        return [
            OwwaFormModalDefaults::createActionForResource(UserResource::class, OwwaFormModalDefaults::WIDTH_MEDIUM)
                ->label('New Users')
                ->extraModalWindowAttributes(['class' => OwwaFormModalDefaults::MODAL_WINDOW_CLASS.' owwa-user-form-modal'])
                ->mutateDataUsing(function (array $data) use (&$generatedPassword, &$pendingAssignments): array {
                    $generatedPassword = TemporaryPassword::generate();
                    $data['password'] = $generatedPassword;
                    $data['email_verified_at'] = null;
                    $data['must_change_password'] = true;

                    $data = UserAssignmentActionHooks::prepareCreateData($data);
                    $pendingAssignments = $data['_assignments'] ?? [];
                    unset($data['_assignments']);

                    return $data;
                })
                ->after(function (User $record) use (&$generatedPassword, &$welcomeMailResult, &$pendingAssignments): void {
                    if ($record->isUnitConsolidator() && $pendingAssignments !== []) {
                        $record->syncOfficeAssignments($pendingAssignments);
                    }

                    $welcomeMailResult = MailDelivery::notify($record, new UserWelcomeNotification(
                        $generatedPassword ?? '',
                        User::panelLoginUrlFor($record),
                        User::guestEmailVerificationUrlFor($record),
                    ));
                })
                ->successNotification(function (Model $record) use (&$generatedPassword, &$welcomeMailResult): Notification {
                    $password = $generatedPassword ?? '—';

                    if ($welcomeMailResult?->success && $welcomeMailResult->wasQueued) {
                        return Notification::make()
                            ->title('User created')
                            ->success()
                            ->body(FriendlyMessages::welcomeEmailQueued($record->email, $password))
                            ->seconds(16);
                    }

                    if ($welcomeMailResult?->success) {
                        return Notification::make()
                            ->title('User created')
                            ->success()
                            ->body(FriendlyMessages::welcomeEmailSent($record->email, $password))
                            ->seconds(12);
                    }

                    return Notification::make()
                        ->title('User created')
                        ->warning()
                        ->body(FriendlyMessages::welcomeEmailFailed($record->email, $password))
                        ->seconds(24);
                }),
        ];
    }

    protected function setupToolbarCreateLabel(): ?string
    {
        return 'New Users';
    }

    protected function setupActiveTabArchivedCount(): int
    {
        return (int) UserResource::getEloquentQuery()->where('role', User::ROLE_SYSTEM_ADMIN)->count();
    }
}
