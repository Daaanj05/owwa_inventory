<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\HasSetupActiveTabToolbar;
use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Filament\Support\UserAssignmentActionHooks;
use App\Models\User;
use App\Services\EmailVerificationCodeService;
use App\Services\PasswordResetCodeService;
use App\Support\FriendlyMessages;
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

        app(PasswordResetCodeService::class)->pruneExpiredRequests();
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
        $welcomeMailResult = null;
        $pendingAssignments = [];

        return [
            OwwaFormModalDefaults::createActionForResource(UserResource::class, OwwaFormModalDefaults::WIDTH_MEDIUM)
                ->label('New Users')
                ->extraModalWindowAttributes(['class' => OwwaFormModalDefaults::MODAL_WINDOW_CLASS.' owwa-user-form-modal'])
                ->mutateDataUsing(function (array $data) use (&$pendingAssignments): array {
                    $data['password'] = TemporaryPassword::generate();
                    $data['email_verified_at'] = null;
                    $data['must_change_password'] = false;

                    $data = UserAssignmentActionHooks::prepareCreateData($data);
                    $pendingAssignments = $data['_assignments'] ?? [];
                    unset($data['_assignments']);

                    return $data;
                })
                ->after(function (User $record) use (&$welcomeMailResult, &$pendingAssignments): void {
                    if ($record->isUnitConsolidator() && $pendingAssignments !== []) {
                        $record->syncOfficeAssignments($pendingAssignments);
                    }

                    $welcomeMailResult = app(EmailVerificationCodeService::class)->sendSetupInvite($record);
                })
                ->successNotification(function (Model $record) use (&$welcomeMailResult): Notification {
                    if ($welcomeMailResult?->success && $welcomeMailResult->wasQueued) {
                        return Notification::make()
                            ->title('User created')
                            ->success()
                            ->body(FriendlyMessages::welcomeEmailQueued($record->email))
                            ->seconds(16);
                    }

                    if ($welcomeMailResult?->success) {
                        return Notification::make()
                            ->title('User created')
                            ->success()
                            ->body(FriendlyMessages::welcomeEmailSent($record->email))
                            ->seconds(12);
                    }

                    return Notification::make()
                        ->title('User created')
                        ->warning()
                        ->body(FriendlyMessages::welcomeEmailFailed($record->email))
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
