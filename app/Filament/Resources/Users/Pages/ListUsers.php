<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\HasSystemAdminWizardHeading;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\OwwaFormModalDefaults;
use App\Models\User;
use App\Notifications\UserWelcomeNotification;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ListUsers extends ListRecords
{
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

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        $total = User::count();
        $custodians = User::where('role', User::ROLE_SUPPLY_CUSTODIAN)->count();

        if ($total === 0) {
            return 'No users yet.';
        }

        $line = "{$total} ".\Illuminate\Support\Str::plural('user', $total).", {$custodians} Supply ".\Illuminate\Support\Str::plural('Custodian', $custodians).'.';

        return $line.' Archived lists System Admin accounts.';
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
            'all' => Tab::make('All'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $generatedPassword = null;

        $createAction = OwwaFormModalDefaults::createAction(OwwaFormModalDefaults::WIDTH_COMPACT)
            ->mutateDataUsing(function (array $data) use (&$generatedPassword): array {
                $generatedPassword = $this->generateTemporaryPassword();
                $data['password'] = $generatedPassword;
                $data['email_verified_at'] = null;
                $data['must_change_password'] = true;

                return $data;
            })
            ->after(function (User $record) use (&$generatedPassword): void {
                $panel = Filament::getPanel($record->isSystemAdmin() ? 'system-admin' : 'admin');

                $record->notify(new UserWelcomeNotification(
                    $generatedPassword ?? '',
                    User::panelLoginUrlFor($record),
                    $panel->getVerifyEmailUrl($record),
                ));
            })
            ->successNotification(function (Model $record) use (&$generatedPassword): Notification {
                $password = $generatedPassword ?? '—';

                return Notification::make()
                    ->title('User created')
                    ->success()
                    ->body(sprintf(
                        'Welcome email sent to %s. Temporary password (backup): %s',
                        $record->email,
                        $password,
                    ))
                    ->seconds(12);
            });

        return $schema
            ->components([
                Flex::make([
                    $this->getTabsContentComponent(),
                    Actions::make([
                        $createAction,
                    ])->alignEnd(),
                ])->alignBetween()->verticallyAlignCenter(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function generateTemporaryPassword(): string
    {
        $upper = strtoupper(Str::random(1));
        $lower = strtolower(Str::random(3));
        $digits = (string) random_int(1000, 9999);
        $symbols = '!@#$%&*';
        $symbol = $symbols[random_int(0, strlen($symbols) - 1)];
        $tail = Str::random(4);

        return str_shuffle($upper.$lower.$digits.$symbol.$tail);
    }
}
